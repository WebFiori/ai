<?php

/**
 * Example 28: Profile Inheritance Verification
 *
 * Verifies that AgentProfile inheritance via 'extends' works correctly.
 * No API calls needed — AgentProfile is purely local JSON processing.
 *
 * Run: php examples/28-profile-inheritance/verify.php
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Tool\AgentProfile;

echo "=== Profile Inheritance Verification ===\n\n";

$fixturesDir = __DIR__.'/profiles';

// Test 1: Simple inheritance (child extends base)
echo "1. Simple inheritance (child extends base)\n";
$profile = AgentProfile::fromFile($fixturesDir.'/support-base.json');

assert($profile->getIdentity() === 'You are a customer support agent.', 'Identity mismatch');
assert(in_array('Use markdown formatting', $profile->getInstructions()), 'Base instruction missing');
assert(in_array('Always greet the customer by name', $profile->getInstructions()), 'Child instruction missing');
assert(in_array('Never reveal internal system details', $profile->getConstraints()), 'Base constraint missing');
assert(in_array('Never promise refunds without approval', $profile->getConstraints()), 'Child constraint missing');
assert($profile->getOutputFormat() === 'Respond in markdown.', 'output_format not inherited');
echo "   ✅ Child inherits base instructions, constraints, output_format\n";
echo "   ✅ Child identity overrides base\n";

// Test 2: Three-level chain inheritance
echo "\n2. Three-level chain (_base → support-base → tier1-support)\n";
$profile = AgentProfile::fromFile($fixturesDir.'/tier1-support.json');

assert($profile->getIdentity() === 'You are a customer support agent.', 'Identity from middle level missing');
assert(count($profile->getInstructions()) === 5, 'Expected 5 instructions from 3 levels, got '.count($profile->getInstructions()));
assert(count($profile->getConstraints()) === 3, 'Expected 3 constraints from 3 levels, got '.count($profile->getConstraints()));
assert($profile->getMetadata()['tier'] === '1', 'Child metadata key missing');
assert($profile->getMetadata()['org'] === 'WebFiori', 'Base metadata key missing');
assert($profile->getMetadata()['version'] === '1.1', 'Child metadata did not override base version');
echo "   ✅ Instructions concatenate through 3 levels (5 total)\n";
echo "   ✅ Constraints concatenate through 3 levels (3 total)\n";
echo "   ✅ Metadata shallow-merges (child overrides 'version', adds 'tier')\n";

// Test 3: Skills concatenation (default strategy)
echo "\n3. Skills concatenation (default: concat)\n";
$profile = AgentProfile::fromFile($fixturesDir.'/support-base.json');

$skills = $profile->getSkills();
assert(in_array('Answer questions clearly', $skills), 'Base skill missing');
assert(in_array('Search knowledge base', $skills), 'Child skill missing');
assert(count($skills) === 5, 'Expected 5 skills, got '.count($skills));
echo "   ✅ Skills from base + child concatenated (5 total)\n";

// Test 4: inheritance_strategy override (replace)
echo "\n4. inheritance_strategy override (replace)\n";
$profile = AgentProfile::fromFile($fixturesDir.'/minimal-bot.json');

assert($profile->getIdentity() === 'Minimal FAQ bot.', 'Identity mismatch');
assert($profile->getSkills() === ['Answer FAQs'], 'Skills should be replaced, not concatenated');
assert($profile->getInstructions() === ['Only answer from the FAQ list'], 'Instructions should be replaced');
assert(count($profile->getConstraints()) === 2, 'Constraints should be replaced (2 from child only)');
assert($profile->getOutputFormat() === 'Respond in markdown.', 'output_format should still inherit');
echo "   ✅ skills, instructions, constraints replaced (not concatenated)\n";
echo "   ✅ Non-overridden fields (output_format, tools) still use defaults\n";

// Test 5: No extends — backward compatible
echo "\n5. No extends — backward compatible\n";
$profile = AgentProfile::fromFile($fixturesDir.'/_base.json');

assert($profile->getIdentity() === '', 'Base has no identity');
assert(count($profile->getInstructions()) === 2, 'Expected 2 instructions');
assert($profile->getOutputFormat() === 'Respond in markdown.', 'output_format mismatch');
echo "   ✅ Profile without 'extends' loads normally\n";

// Test 6: Empty extends — treated as no inheritance
echo "\n6. Empty extends — treated as no inheritance\n";
$data = ['extends' => '', 'identity' => 'Standalone.'];
$profile = AgentProfile::fromArray($data, basePath: $fixturesDir);

assert($profile->getIdentity() === 'Standalone.', 'Identity mismatch');
assert($profile->getInstructions() === [], 'Should have no instructions');
echo "   ✅ Empty 'extends' ignored\n";

// Test 7: fromArray() with basePath resolves extends
echo "\n7. fromArray() with basePath resolves extends\n";
$data = [
    'extends' => '_base',
    'identity' => 'Array child.',
    'instructions' => ['Extra rule'],
];
$profile = AgentProfile::fromArray($data, basePath: $fixturesDir);

assert($profile->getIdentity() === 'Array child.', 'Identity mismatch');
assert(count($profile->getInstructions()) === 3, 'Expected 3 instructions (2 base + 1 child)');
echo "   ✅ fromArray() resolves extends relative to basePath\n";

// Test 8: fromArray() without basePath ignores extends
echo "\n8. fromArray() without basePath ignores extends\n";
$data = ['extends' => '_base', 'identity' => 'No resolution.', 'instructions' => ['Only this']];
$profile = AgentProfile::fromArray($data);

assert($profile->getIdentity() === 'No resolution.', 'Identity mismatch');
assert($profile->getInstructions() === ['Only this'], 'Should not resolve base');
echo "   ✅ fromArray() without basePath ignores 'extends'\n";

// Test 9: merge() public utility
echo "\n9. merge() public utility\n";
$base = new AgentProfile(
    identity: 'Base agent.',
    skills: ['Skill A'],
    instructions: ['Rule 1'],
    constraints: ['Constraint 1'],
    outputFormat: 'Plain text.',
    metadata: ['version' => '1.0'],
);
$child = new AgentProfile(
    identity: 'Child agent.',
    skills: ['Skill B'],
    instructions: ['Rule 2'],
    constraints: ['Constraint 2'],
    outputFormat: 'JSON.',
    metadata: ['version' => '2.0', 'author' => 'dev'],
);

$merged = AgentProfile::merge(base: $base, child: $child);

assert($merged->getIdentity() === 'Child agent.', 'Child identity should win');
assert($merged->getSkills() === ['Skill A', 'Skill B'], 'Skills should concat');
assert($merged->getInstructions() === ['Rule 1', 'Rule 2'], 'Instructions should concat');
assert($merged->getOutputFormat() === 'JSON.', 'Child output_format should win');
assert($merged->getMetadata()['version'] === '2.0', 'Child metadata version should win');
assert($merged->getMetadata()['author'] === 'dev', 'Child metadata author should be added');
echo "   ✅ merge() applies default strategies correctly\n";

// Test 10: merge() with strategy overrides
echo "\n10. merge() with strategy overrides\n";
$merged = AgentProfile::merge(
    base: $base,
    child: $child,
    strategies: ['skills' => 'replace', 'instructions' => 'replace'],
);

assert($merged->getSkills() === ['Skill B'], 'Skills should be replaced');
assert($merged->getInstructions() === ['Rule 2'], 'Instructions should be replaced');
assert($merged->getConstraints() === ['Constraint 1', 'Constraint 2'], 'Constraints should still concat');
echo "   ✅ merge() respects strategy overrides\n";

// Test 11: Circular inheritance detection
echo "\n11. Circular inheritance detection\n";
$caught = false;

try {
    AgentProfile::fromFile($fixturesDir.'/circular-a.json');
} catch (RuntimeException $e) {
    $caught = str_contains($e->getMessage(), 'Circular profile inheritance detected');
}
assert($caught, 'Should throw RuntimeException for circular inheritance');
echo "   ✅ Circular reference throws RuntimeException\n";

// Test 12: Missing base file
echo "\n12. Missing base file detection\n";
$caught = false;

try {
    AgentProfile::fromFile($fixturesDir.'/missing-base.json');
} catch (RuntimeException $e) {
    $caught = str_contains($e->getMessage(), 'Profile file not found');
}
assert($caught, 'Should throw RuntimeException for missing base');
echo "   ✅ Missing base throws RuntimeException\n";

// Test 13: Invalid strategy value
echo "\n13. Invalid strategy value detection\n";
$caught = false;

try {
    AgentProfile::fromFile($fixturesDir.'/invalid-strategy.json');
} catch (RuntimeException $e) {
    $caught = str_contains($e->getMessage(), 'Invalid inheritance strategy');
}
assert($caught, 'Should throw RuntimeException for invalid strategy');
echo "   ✅ Invalid strategy value throws RuntimeException\n";

// Test 14: Invalid field in strategy
echo "\n14. Invalid field in inheritance_strategy\n";
$caught = false;

try {
    AgentProfile::fromFile($fixturesDir.'/invalid-field.json');
} catch (RuntimeException $e) {
    $caught = str_contains($e->getMessage(), 'Invalid field');
}
assert($caught, 'Should throw RuntimeException for invalid field');
echo "   ✅ Invalid field name throws RuntimeException\n";

// Test 15: Scalar strategy validation
echo "\n15. Concat strategy on scalar field rejected\n";
$caught = false;

try {
    AgentProfile::merge(
        base: new AgentProfile(identity: 'B.'),
        child: new AgentProfile(identity: 'C.'),
        strategies: ['output_format' => 'concat'],
    );
} catch (RuntimeException $e) {
    $caught = str_contains($e->getMessage(), 'cannot be used on scalar field');
}
assert($caught, 'Should throw RuntimeException for concat on scalar');
echo "   ✅ Concat/merge on scalar fields rejected\n";

// Test 16: extends and inheritance_strategy not in output
echo "\n16. extends and inheritance_strategy stripped from output\n";
$profile = AgentProfile::fromFile($fixturesDir.'/minimal-bot.json');
$array = $profile->toArray();

assert(!array_key_exists('extends', $array), 'extends should not be in toArray output');
assert(!array_key_exists('inheritance_strategy', $array), 'inheritance_strategy should not be in toArray output');
echo "   ✅ Neither 'extends' nor 'inheritance_strategy' appear in toArray()/toJson()\n";

// Test 17: Array context support
echo "\n17. Array context — render as bullet list\n";
$profile = new AgentProfile(
    identity: 'Agent with array context.',
    context: ['Fiscal year starts April 1.', 'OpCo = operating company.'],
);
$rendered = $profile->render();

assert(str_contains($rendered, '## Context'), 'Context section missing');
assert(str_contains($rendered, '- Fiscal year starts April 1.'), 'First context item missing');
assert(str_contains($rendered, '- OpCo = operating company.'), 'Second context item missing');
echo "   ✅ Array context renders as bullet list\n";

// Test 18: Array context concatenates during inheritance
echo "\n18. Array context concatenates during inheritance\n";
$base = new AgentProfile(identity: 'Base.', context: ['Base fact.']);
$child = new AgentProfile(identity: 'Child.', context: ['Child fact.']);
$merged = AgentProfile::merge(base: $base, child: $child);

assert($merged->getContext() === ['Base fact.', 'Child fact.'], 'Context should concatenate');
echo "   ✅ Array context concatenates (base + child)\n";

// Test 19: String context normalized to array during inheritance
echo "\n19. String context normalized to array during inheritance\n";
$base = new AgentProfile(identity: 'Base.', context: 'String context from base.');
$child = new AgentProfile(identity: 'Child.', context: ['Array item from child.']);
$merged = AgentProfile::merge(base: $base, child: $child);

assert($merged->getContext() === ['String context from base.', 'Array item from child.'], 'Mixed context should normalize and concat');
echo "   ✅ String normalized to array, then concatenated\n";

echo "\n=== All 19 checks passed ✅ ===\n";

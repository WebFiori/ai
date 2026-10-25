<?php
namespace WebFiori\Tests\Ai\Embedding;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Embedding\SqlServerOption;
use WebFiori\Ai\Embedding\SqlServerVectorStore;

/**
 * Unit tests for SqlServerVectorStore behavior that does not require a live
 * database connection (constructor validation, schema SQL generation, and the
 * SqlServerOption constants). These always run.
 */
class SqlServerVectorStoreUnitTest extends TestCase {
    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidTableNames(): array {
        return [
            'space' => ['bad name'],
            'semicolon' => ['vectors; DROP TABLE x'],
            'quote' => ["vec'tors"],
            'leading num' => ['1vectors'],
            'dash' => ['my-vectors'],
            'empty' => [''],
        ];
    }
    public function testConstructorThrowsOnEmptyServerName(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Server name cannot be empty');

        new SqlServerVectorStore('');
    }

    public function testGetSchemaSqlAllowsSchemaQualifiedName(): void {
        $sql = SqlServerVectorStore::getSchemaSql('dbo.vectors');
        $this->assertStringContainsString('CREATE TABLE dbo.vectors', $sql);
    }

    public function testGetSchemaSqlContainsTableAndColumns(): void {
        $sql = SqlServerVectorStore::getSchemaSql('my_vectors');

        $this->assertStringContainsString('CREATE TABLE my_vectors', $sql);
        $this->assertStringContainsString('id         NVARCHAR(255) NOT NULL PRIMARY KEY', $sql);
        $this->assertStringContainsString('vector     NVARCHAR(MAX) NOT NULL', $sql);
        $this->assertStringContainsString('metadata   NVARCHAR(MAX) NOT NULL', $sql);
        $this->assertStringContainsString('created_at BIGINT        NOT NULL', $sql);
        $this->assertStringContainsString('CREATE INDEX idx_my_vectors_created_at', $sql);
        // Idempotent guard.
        $this->assertStringContainsString("IF OBJECT_ID(N'my_vectors', N'U') IS NULL", $sql);
    }

    public function testGetSchemaSqlDefaultsToVectorsTable(): void {
        $this->assertStringContainsString('CREATE TABLE vectors', SqlServerVectorStore::getSchemaSql());
    }

    /**
     * @dataProvider invalidTableNames
     */
    public function testGetSchemaSqlRejectsInvalidTableNames(string $badName): void {
        $this->expectException(InvalidArgumentException::class);
        SqlServerVectorStore::getSchemaSql($badName);
    }

    public function testOptionClassCannotBeInstantiated(): void {
        $reflection = new \ReflectionClass(SqlServerOption::class);
        $this->assertFalse($reflection->getConstructor()->isPublic());
    }

    public function testOptionConstantValues(): void {
        $this->assertSame('connection_info', SqlServerOption::CONNECTION_INFO);
        $this->assertSame('table', SqlServerOption::TABLE);
        $this->assertSame('auto_create', SqlServerOption::AUTO_CREATE);
        // CONN_* values must equal the exact sqlsrv driver strings.
        $this->assertSame('Database', SqlServerOption::CONN_DATABASE);
        $this->assertSame('UID', SqlServerOption::CONN_UID);
        $this->assertSame('PWD', SqlServerOption::CONN_PWD);
        $this->assertSame('Encrypt', SqlServerOption::CONN_ENCRYPT);
        $this->assertSame('TrustServerCertificate', SqlServerOption::CONN_TRUST_SERVER_CERTIFICATE);
        $this->assertSame('LoginTimeout', SqlServerOption::CONN_LOGIN_TIMEOUT);
    }
}

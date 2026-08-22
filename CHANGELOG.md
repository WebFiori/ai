# Changelog

## [0.5.8](https://github.com/WebFiori/ai/compare/v0.5.7...v0.5.8) (2026-08-22)


### Miscellaneous Chores

* Merge pull request [#115](https://github.com/WebFiori/ai/issues/115) from WebFiori/dev ([79713c7](https://github.com/WebFiori/ai/commit/79713c701fb998b4db861aecacb0fcc829996fce))

## [0.5.7](https://github.com/WebFiori/ai/compare/v0.5.4...v0.5.7) (2026-08-22)


### Features

* **core:** Implement model aliases ([#32](https://github.com/WebFiori/ai/issues/32)) ([f25f2df](https://github.com/WebFiori/ai/commit/f25f2dfbe0064affadd5a22e15e7ee63af79cbc7))
* **providers:** ProviderFormatterInterface + Vertex AI Model Garden ([#111](https://github.com/WebFiori/ai/issues/111), [#93](https://github.com/WebFiori/ai/issues/93)) ([3efb978](https://github.com/WebFiori/ai/commit/3efb9785e689c482d6c6088eed2555232dc09d63))
* **providers:** ProviderFormatterInterface + Vertex AI Model Garden (v0.5.5) ([68c30b9](https://github.com/WebFiori/ai/commit/68c30b95f2711ec5e471778d1ae6da7b33e258d8))


### Bug Fixes

* **live:** Update Model Garden test with correct model ID and quota handling ([c1b0395](https://github.com/WebFiori/ai/commit/c1b0395d33e2ba0b9621b0732cb312a83331ccf7))


### Miscellaneous Chores

* Merge pull request [#114](https://github.com/WebFiori/ai/issues/114) from WebFiori/dev ([35248e8](https://github.com/WebFiori/ai/commit/35248e83d90f136f6612531d5218478bfb811222))

## [0.5.4](https://github.com/WebFiori/ai/compare/v0.5.2...v0.5.4) (2026-08-19)


### Features

* **tools:** ToolResponse class and multimodal tool outputs ([#95](https://github.com/WebFiori/ai/issues/95)-[#98](https://github.com/WebFiori/ai/issues/98)) ([1db45fb](https://github.com/WebFiori/ai/commit/1db45fb33c2c28d632f98c7a927abcf2ccae6b49))


### Miscellaneous Chores

* Merge pull request [#109](https://github.com/WebFiori/ai/issues/109) from WebFiori/dev ([47379ce](https://github.com/WebFiori/ai/commit/47379ce4d16e047e65a4503989945727a4401dfd))

## [0.5.2](https://github.com/WebFiori/ai/compare/v0.5.1...v0.5.2) (2026-08-19)


### Features

* **google:** Add Interactions API message formatter ([#100](https://github.com/WebFiori/ai/issues/100)) ([5e3ef81](https://github.com/WebFiori/ai/commit/5e3ef81e868aa8ad79b9f149d8a1ab7820ed1f5e))
* **google:** Add Interactions API request builder ([#101](https://github.com/WebFiori/ai/issues/101)) ([39cadfd](https://github.com/WebFiori/ai/commit/39cadfd784083543998db71afb4653ad22bc88dd))
* **google:** Add Interactions API response parser ([#102](https://github.com/WebFiori/ai/issues/102)) ([75e3e5e](https://github.com/WebFiori/ai/commit/75e3e5e582d31681e2cd0e5a5b5c95aedd139cdc))
* **google:** Auto-detect API version from model name ([#99](https://github.com/WebFiori/ai/issues/99)) ([b2b3583](https://github.com/WebFiori/ai/commit/b2b35830a870dd8f3fbcec434abef0c48fc44b36))
* **google:** implement Gemini native image generation ([03d8fbb](https://github.com/WebFiori/ai/commit/03d8fbb4231c5f00b8628d58018dfd2a4d9f66d7))
* **google:** implement Gemini native image generation ([8a4aab6](https://github.com/WebFiori/ai/commit/8a4aab694605082b59ebd26cb5884ad7530446e6))
* **google:** Interactions API streaming support ([#104](https://github.com/WebFiori/ai/issues/104)) ([64d3bcb](https://github.com/WebFiori/ai/commit/64d3bcb83a089046682f06f954ad506272a73573))
* **google:** Support tool execution loop for Interactions API ([#103](https://github.com/WebFiori/ai/issues/103)) ([ade0b99](https://github.com/WebFiori/ai/commit/ade0b995f5f024b2965735d21aa6cbc092c88c7e))
* **resilience:** Implement provider fallback with automatic failover ([ad1f219](https://github.com/WebFiori/ai/commit/ad1f21930a639bacf2e9baee669aa86988cff513)), closes [#31](https://github.com/WebFiori/ai/issues/31)
* **tools:** enrich FileProcessing converter metadata ([d222111](https://github.com/WebFiori/ai/commit/d2221118f0e4172770fce1c6c9af8675ac85b47a))
* **tools:** enrich FileProcessing converter metadata ([b03a800](https://github.com/WebFiori/ai/commit/b03a8000c5b9b49bdd0aa487e59d49a8fa29b6e4))


### Bug Fixes

* **bedrock:** Fix SigV4 signature mismatch in streaming requests ([d4565dd](https://github.com/WebFiori/ai/commit/d4565dd023df3a3d0cf40b490ab9fccac57fe0f2))
* **google:** Update Interactions API to match real gemini-3.5-flash format ([fa33c22](https://github.com/WebFiori/ai/commit/fa33c22419076a3bfe766dd1de6128ffe662bef1))


### Miscellaneous Chores

* **main:** release 0.5.1 ([93e71cf](https://github.com/WebFiori/ai/commit/93e71cfc4a145eff64277cfe3456a233dda6ea8a))
* **main:** release 0.5.1 ([10b9b07](https://github.com/WebFiori/ai/commit/10b9b072f4b5e3e282f280114fa3e6c5a5663570))
* Merge pull request [#105](https://github.com/WebFiori/ai/issues/105) from WebFiori/dev ([7850f73](https://github.com/WebFiori/ai/commit/7850f73fb56ec42532525c4cba96fd251a5d4db4))
* Merge pull request [#107](https://github.com/WebFiori/ai/issues/107) from WebFiori/dev ([3b2d179](https://github.com/WebFiori/ai/commit/3b2d17904fdee2522b0e6aa133ca72bd39201399))
* Move credential files to keys/ directory ([b258f2b](https://github.com/WebFiori/ai/commit/b258f2b0806687e1b5e1a2d19e723650d79e8149))

## [0.5.1](https://github.com/WebFiori/ai/compare/v0.5.1...v0.5.1) (2026-08-19)


### Features

* **google:** implement Gemini native image generation ([03d8fbb](https://github.com/WebFiori/ai/commit/03d8fbb4231c5f00b8628d58018dfd2a4d9f66d7))
* **google:** implement Gemini native image generation ([8a4aab6](https://github.com/WebFiori/ai/commit/8a4aab694605082b59ebd26cb5884ad7530446e6))
* **resilience:** Implement provider fallback with automatic failover ([ad1f219](https://github.com/WebFiori/ai/commit/ad1f21930a639bacf2e9baee669aa86988cff513)), closes [#31](https://github.com/WebFiori/ai/issues/31)
* **tools:** enrich FileProcessing converter metadata ([d222111](https://github.com/WebFiori/ai/commit/d2221118f0e4172770fce1c6c9af8675ac85b47a))
* **tools:** enrich FileProcessing converter metadata ([b03a800](https://github.com/WebFiori/ai/commit/b03a8000c5b9b49bdd0aa487e59d49a8fa29b6e4))


### Miscellaneous Chores

* Merge pull request [#105](https://github.com/WebFiori/ai/issues/105) from WebFiori/dev ([7850f73](https://github.com/WebFiori/ai/commit/7850f73fb56ec42532525c4cba96fd251a5d4db4))

## [Unreleased]

### Features

* **resilience:** Implement provider fallback with automatic failover ([#31](https://github.com/WebFiori/ai/issues/31))
  - `FallbackProvider` wraps multiple providers with automatic failover
  - Three routing strategies: `sequential`, `round_robin`, `weighted`
  - Circuit breaker pattern to avoid hammering failing providers
  - Configurable failure conditions (which exceptions trigger failover)
  - Metrics callback for observability
  - Full `ProviderInterface` implementation - transparent to callers

## [0.5.1](https://github.com/WebFiori/ai/compare/v0.5.0...v0.5.1) (2026-08-16)


### Features

* **auth:** add Google ADC and AWS credential chain support ([892b3d2](https://github.com/WebFiori/ai/commit/892b3d29f31ed4a6ae94a4870264eb392648c330))
* RAG pipeline, FileContentExtractor, built-in tools grounding fix, Google ADC, AWS credential chain ([6f874dc](https://github.com/WebFiori/ai/commit/6f874dc39afa8c48a4c5c4e95b4fa5190e4f0e2f))
* **tools:** add FileContentExtractor universal file-to-text tool ([111224a](https://github.com/WebFiori/ai/commit/111224a2a25680aa366471df2f3ccb0f1a98a3b8)), closes [#61](https://github.com/WebFiori/ai/issues/61)
* **tools:** support provider-native built-in tools alongside function calling ([f10ab09](https://github.com/WebFiori/ai/commit/f10ab09c827392af96caaf37ee56c180d999caa1)), closes [#78](https://github.com/WebFiori/ai/issues/78)


### Miscellaneous Chores

* **main:** release 0.5.0 ([6a6fe3a](https://github.com/WebFiori/ai/commit/6a6fe3aeced556a811e3cddb28e8f814dee99f4e))
* Merge pull request [#80](https://github.com/WebFiori/ai/issues/80) from WebFiori/dev ([e80a842](https://github.com/WebFiori/ai/commit/e80a8420faeb5769fe4316d6ca365ee21ffb9eec))

## [0.5.0](https://github.com/WebFiori/ai/compare/v0.4.9...v0.5.0) (2026-08-15)


### Features

* **chat:** Implement structured output / JSON mode ([2e4d110](https://github.com/WebFiori/ai/commit/2e4d1107d221efcb0e7d1ebaae4c35793c9b4453)), closes [#30](https://github.com/WebFiori/ai/issues/30)
* **rag:** add RAG pipeline with document chunking and vector stores ([bc631d3](https://github.com/WebFiori/ai/commit/bc631d37c405756cece6e86e8428a0d310f5e6a2))
* **status:** Add real-time status events for progress tracking ([55666ab](https://github.com/WebFiori/ai/commit/55666abaeea2a8918aa330c5e1f1ac02897ed883)), closes [#69](https://github.com/WebFiori/ai/issues/69)
* **status:** Add StatusMessageFormatter for humanized status messages ([649ad5b](https://github.com/WebFiori/ai/commit/649ad5ba87546b4578146e89c65b3c942c59f659))


### Performance Improvements

* Avoid message re-formatting in tool loops and fetch images concurrently ([fd4bc0b](https://github.com/WebFiori/ai/commit/fd4bc0b21a17deadc3b7b4cfd22f4a74dabd94c0))


### Miscellaneous Chores

* Merge pull request [#74](https://github.com/WebFiori/ai/issues/74) from WebFiori/dev ([1fd61c1](https://github.com/WebFiori/ai/commit/1fd61c1e4b2cc3afeea1e440194ffb33a8374235))
* Merge pull request [#79](https://github.com/WebFiori/ai/issues/79) from WebFiori/dev ([3c4acaf](https://github.com/WebFiori/ai/commit/3c4acaf372832e6ace1e3b03cd60969b36541c93))

## [0.4.9](https://github.com/WebFiori/ai/compare/v0.4.8...v0.4.9) (2026-08-12)


### Miscellaneous Chores

* Merge pull request [#70](https://github.com/WebFiori/ai/issues/70) from WebFiori/dev ([5e0dc66](https://github.com/WebFiori/ai/commit/5e0dc66341f18acde9fa5d5ceaf9dc4dba18dfe3))

## [0.4.8](https://github.com/WebFiori/ai/compare/v0.4.7...v0.4.8) (2026-08-12)


### Features

* **chat:** expand multi-modal to support documents, audio, and video ([a73b3db](https://github.com/WebFiori/ai/commit/a73b3dbf0b752dfc31af38c4667471b4145a5fe1))
* **chat:** implement multi-modal support (images in chat) ([caa36e4](https://github.com/WebFiori/ai/commit/caa36e48edfceb71b9b856cc797afe69ad905ac6))
* **chat:** implement multi-modal support (images in chat) ([e12006d](https://github.com/WebFiori/ai/commit/e12006d82ee40b65ad7885c15c01437008db376e)), closes [#29](https://github.com/WebFiori/ai/issues/29)
* merge health checks into main ([eba9bed](https://github.com/WebFiori/ai/commit/eba9bed5bc5c99b0efc39f5ef32058de7ced8e61))


### Miscellaneous Chores

* Merge pull request [#58](https://github.com/WebFiori/ai/issues/58) from WebFiori/feat/audit-logging ([9d459c5](https://github.com/WebFiori/ai/commit/9d459c5495ef81e7b67771dd0cb6ce7e2d9bb15c))

## [0.4.7](https://github.com/WebFiori/ai/compare/v0.4.6...v0.4.7) (2026-08-10)


### Features

* **redaction:** implement PII redaction for logs and metrics ([e0a6b42](https://github.com/WebFiori/ai/commit/e0a6b42f7977ab57bb614e3802146de90fbcb36d)), closes [#26](https://github.com/WebFiori/ai/issues/26)


### Miscellaneous Chores

* Merge pull request [#56](https://github.com/WebFiori/ai/issues/56) from WebFiori/feat/pii-redaction ([5ce187b](https://github.com/WebFiori/ai/commit/5ce187b315dddeef1224a215f379fb060b51c4f5))

## [0.4.6](https://github.com/WebFiori/ai/compare/v0.4.5...v0.4.6) (2026-08-10)


### Features

* **metrics:** implement metrics collection via callback ([f1e117f](https://github.com/WebFiori/ai/commit/f1e117f73574bf923da50e9a4872f384df9147c7)), closes [#25](https://github.com/WebFiori/ai/issues/25)


### Bug Fixes

* remove debug file_put_contents from parseChatResponse ([094fdec](https://github.com/WebFiori/ai/commit/094fdecf817637f30c5a8a00adee8c87517f3918))


### Miscellaneous Chores

* Merge pull request [#55](https://github.com/WebFiori/ai/issues/55) from WebFiori/feat/metrics ([b38e830](https://github.com/WebFiori/ai/commit/b38e830e348a2d8b8a69407043b27e3f0a459eb6))

## [0.4.5](https://github.com/WebFiori/ai/compare/v0.4.3...v0.4.5) (2026-08-08)


### Features

* **context:** implement token counting and context window management ([ab164f1](https://github.com/WebFiori/ai/commit/ab164f16df64ab52a63a777002f9328a8d07a7a5))
* **context:** implement token counting and context window management ([20cc310](https://github.com/WebFiori/ai/commit/20cc310c1d1e484f500962ce2d39d60c6256502b)), closes [#28](https://github.com/WebFiori/ai/issues/28)
* **health:** implement provider health checks ([40411c7](https://github.com/WebFiori/ai/commit/40411c71103fe15c0c41592446556424a22ca5d0)), closes [#24](https://github.com/WebFiori/ai/issues/24)


### Miscellaneous Chores

* Merge pull request [#53](https://github.com/WebFiori/ai/issues/53) from WebFiori/feat/health-checks ([fbdcc01](https://github.com/WebFiori/ai/commit/fbdcc0177267368d96b3d1c71a56ea107d662671))

## [0.4.3](https://github.com/WebFiori/ai/compare/v0.4.0...v0.4.3) (2026-08-06)


### Features

* **cache:** implement response caching interface ([7efc501](https://github.com/WebFiori/ai/commit/7efc5016ec4dc5c34dc859a61e7e725bc1160a72)), closes [#23](https://github.com/WebFiori/ai/issues/23)
* **google:** support global location endpoint for Vertex AI ([fcf0322](https://github.com/WebFiori/ai/commit/fcf0322a816373b3b047458f4c9b46502ba42d27))
* **resilience:** rate limit header tracking ([2cc8b38](https://github.com/WebFiori/ai/commit/2cc8b38329456d07418cfe306a54feb30b283e40))
* **resilience:** rate limit tracking release trigger ([3ba16ad](https://github.com/WebFiori/ai/commit/3ba16adfe8ee543fb69f839047389042f2606661)), closes [#22](https://github.com/WebFiori/ai/issues/22)


### Bug Fixes

* cast empty functionCall args to object to avoid JSON list serialization ([5f916d0](https://github.com/WebFiori/ai/commit/5f916d0921feea8f8b266757c705fddb69713f2b))
* merge consecutive tool messages into single function role content for Gemini ([7f66ea3](https://github.com/WebFiori/ai/commit/7f66ea3d6d4d76300ce4d5a6bf763953696b08eb))
* preserve thought_signature and raw parts for Gemini tool calls ([6e8bad8](https://github.com/WebFiori/ai/commit/6e8bad8920eb7844d9f16b33f0e020e22ee17426))
* skip thought parts in parseChatResponse for Gemini thinking mode ([bc2a7ff](https://github.com/WebFiori/ai/commit/bc2a7ffdd3384755f12cc01d606d90eb638a6e2d))


### Miscellaneous Chores

* release v0.4.3 ([2070fc5](https://github.com/WebFiori/ai/commit/2070fc5373a029349351680edea34a9ccd805d1e))

## [0.4.0](https://github.com/WebFiori/ai/compare/v0.3.0...v0.4.0) (2026-07-26)


### Features

* **resilience:** implement retry with exponential backoff ([59bcb97](https://github.com/WebFiori/ai/commit/59bcb9716c60c83d1a40a13e3ed6f4525b2b70bd)), closes [#21](https://github.com/WebFiori/ai/issues/21)
* **resilience:** retry with exponential backoff ([c9d644f](https://github.com/WebFiori/ai/commit/c9d644fa0164e240bb5c447a48830c38120d2e6d))

## [0.3.0](https://github.com/WebFiori/ai/compare/v0.2.0...v0.3.0) (2026-07-26)


### Features

* **bedrock:** add API key authentication support ([f0da558](https://github.com/WebFiori/ai/commit/f0da5589e45c1dc1da899e85cd3a01198d670e87))
* **provider:** implement Anthropic Claude provider ([f6b0c62](https://github.com/WebFiori/ai/commit/f6b0c62c58d8fe355f535c9a571376d8f5c7b61e)), closes [#19](https://github.com/WebFiori/ai/issues/19)
* **provider:** implement AWS Bedrock provider ([1a27193](https://github.com/WebFiori/ai/commit/1a27193463d1400f7b377cf91050da64132f17b8)), closes [#20](https://github.com/WebFiori/ai/issues/20)


### Bug Fixes

* **bedrock:** implement EventStreamParser for Converse streaming ([9b8858d](https://github.com/WebFiori/ai/commit/9b8858da4121ebfe2cf52372061faca014788840))
* **google:** cast empty tool call args to object for valid JSON ([27b7a29](https://github.com/WebFiori/ai/commit/27b7a294102cbfde3858211bbd3ac183f6c0a951))
* **google:** ensure functionCall.args and functionResponse.response are always JSON objects ([922a517](https://github.com/WebFiori/ai/commit/922a517083142a4574bd884bd1c3711c654fcb6f))


### Miscellaneous Chores

* Merge pull request [#46](https://github.com/WebFiori/ai/issues/46) from WebFiori/dev ([c9ab008](https://github.com/WebFiori/ai/commit/c9ab008aa73511ad1decb0f74ab4df1246e09f48))

## [0.2.0](https://github.com/WebFiori/ai/compare/v0.1.0...v0.2.0) (2026-07-15)


### Features

* **embeddings:** implement vector storage interface ([db69891](https://github.com/WebFiori/ai/commit/db69891f9af4dbb46d9a168bc548b3176d126aec)), closes [#12](https://github.com/WebFiori/ai/issues/12)
* **google:** add api_key authentication for Gemini API ([2c91341](https://github.com/WebFiori/ai/commit/2c91341710795967970e2ccea9f4c1f1b47756e4))
* **tools:** implement tool/function calling support ([56c9ba6](https://github.com/WebFiori/ai/commit/56c9ba684750a99f80768c58d084298b2f3bffca)), closes [#13](https://github.com/WebFiori/ai/issues/13)


### Bug Fixes

* **google:** support Gemini API embeddings and switch examples to Google ([76c28c4](https://github.com/WebFiori/ai/commit/76c28c45841a577643dc64184562cf6496afaeee))


### Miscellaneous Chores

* Merge pull request [#43](https://github.com/WebFiori/ai/issues/43) from WebFiori/dev ([dfed72d](https://github.com/WebFiori/ai/commit/dfed72deddbc969e58eb3c0f8ae8443172bb0775))
* switch line endings to LF in .gitattributes ([71fd3a8](https://github.com/WebFiori/ai/commit/71fd3a8ac165d55616574d8124c272af39835cc7))

## 0.1.0 (2026-07-06)


### Features

* **chat:** implement chat completions base in AbstractProvider ([c1b0ffa](https://github.com/WebFiori/ai/commit/c1b0ffab5597fd0196b7eceb37cfd1588d458f7f)), closes [#6](https://github.com/WebFiori/ai/issues/6)
* **chat:** implement SSE streaming parser ([cd6d77a](https://github.com/WebFiori/ai/commit/cd6d77a1aab16fcd4bf4263c0da2a06a0d605028)), closes [#7](https://github.com/WebFiori/ai/issues/7)
* **conversation:** implement conversation management with swappable storage ([ff3c1cc](https://github.com/WebFiori/ai/commit/ff3c1cc467d1d4c41108b7b9b5776822788ea7a4)), closes [#10](https://github.com/WebFiori/ai/issues/10)
* **core:** define core interfaces and abstractions ([4145e14](https://github.com/WebFiori/ai/commit/4145e14fa9429187b1b06a4aa3f3508d4764661e)), closes [#2](https://github.com/WebFiori/ai/issues/2)
* **core:** implement exception hierarchy ([1497197](https://github.com/WebFiori/ai/commit/14971975e67155e92f40a9696aed7e1df1e92a6e)), closes [#4](https://github.com/WebFiori/ai/issues/4)
* **core:** implement logging via callback ([5e20744](https://github.com/WebFiori/ai/commit/5e207449e4ea02e2b750a779f5d8330e379a03e5)), closes [#5](https://github.com/WebFiori/ai/issues/5)
* **http:** implement cURL-based HTTP client ([2c331f0](https://github.com/WebFiori/ai/commit/2c331f0d22d55005768ea47a9464f6d1bb0363b7)), closes [#3](https://github.com/WebFiori/ai/issues/3)
* **project:** initial project scaffolding ([0e7df5c](https://github.com/WebFiori/ai/commit/0e7df5c9b8bb0941032fb4286266b0e05cac9965)), closes [#1](https://github.com/WebFiori/ai/issues/1)
* **provider:** implement GCP Vertex AI provider ([8f68bf1](https://github.com/WebFiori/ai/commit/8f68bf1768506886ab4b99d467c8c999109540a1)), closes [#9](https://github.com/WebFiori/ai/issues/9)
* **provider:** implement OpenAI provider ([f62a7ff](https://github.com/WebFiori/ai/commit/f62a7ff310d067734fedc5822f51ec5c665e67fd)), closes [#8](https://github.com/WebFiori/ai/issues/8)


### Miscellaneous Chores

* Merge pull request [#40](https://github.com/WebFiori/ai/issues/40) from WebFiori/dev ([bcbc090](https://github.com/WebFiori/ai/commit/bcbc090f887109f0acc2b6ad83a5223c37db6520))

## Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

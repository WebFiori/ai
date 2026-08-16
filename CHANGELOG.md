# Changelog

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

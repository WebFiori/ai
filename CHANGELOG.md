# Changelog

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

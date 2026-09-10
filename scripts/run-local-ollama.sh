#!/bin/sh
# Project-owned Ollama server. Keep other Ollama clients idle during benchmarks.
# These variables belong to the Ollama server, not to FastAPI's .env.
export OLLAMA_HOST="${OLLAMA_HOST:-127.0.0.1:11435}"
export OLLAMA_NUM_PARALLEL="${OLLAMA_NUM_PARALLEL:-1}"
export OLLAMA_MAX_LOADED_MODELS="${OLLAMA_MAX_LOADED_MODELS:-1}"
export OLLAMA_FLASH_ATTENTION="${OLLAMA_FLASH_ATTENTION:-1}"
export OLLAMA_KV_CACHE_TYPE="${OLLAMA_KV_CACHE_TYPE:-q8_0}"
export OLLAMA_CONTEXT_LENGTH="${OLLAMA_CONTEXT_LENGTH:-8192}"
exec ollama serve

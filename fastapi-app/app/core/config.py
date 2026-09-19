from enum import StrEnum
from functools import lru_cache
from pathlib import Path

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict

from app.processing.base import ProcessingProfile


class DeploymentMode(StrEnum):
    CLOUD = "cloud"
    LOCAL = "local"


class LocalAiTopology(StrEnum):
    HOST_NATIVE = "host_native"


class LocalDevice(StrEnum):
    AUTO = "auto"
    CUDA = "cuda"
    ROCM = "rocm"
    XPU = "xpu"
    MPS = "mps"
    CPU = "cpu"


class LocalDtype(StrEnum):
    AUTO = "auto"


class StartupConfigurationError(RuntimeError):
    pass


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_name: str = "RAG AI Service"
    app_version: str = "0.1.0"

    rag_deployment_mode: DeploymentMode = DeploymentMode.LOCAL
    local_ai_topology: LocalAiTopology | None = None
    local_device: LocalDevice = LocalDevice.AUTO
    local_dtype: LocalDtype = LocalDtype.AUTO

    internal_api_key: SecretStr | None = None
    llama_cloud_api_key: SecretStr | None = None
    llamaparse_upload_timeout_seconds: float = Field(
        default=900.0,
        gt=0,
        le=3600,
    )
    llamaparse_parse_timeout_seconds: float = Field(
        default=7200.0,
        gt=0,
        le=14400,
    )
    parse_checkpoint_dir: Path = Path(".cache/parse-checkpoints")
    hf_token: SecretStr | None = None
    cloud_llm_model: str = "Qwen/Qwen3.5-9B"
    ollama_base_url: str = "http://127.0.0.1:11434"
    local_llm_model: str = "qwen3.5:4b"
    ollama_keep_alive: str = "0"
    local_llm_tokenizer_path: str | None = None
    local_llm_num_ctx: int = Field(default=8192, ge=2048, le=32768)
    rag_generation_max_tokens: int = Field(default=768, ge=64, le=2048)

    ollama_request_timeout_seconds: float = Field(
        default=300.0,
        gt=0,
        le=600,
    )
    rag_generation_temperature: float = Field(
        default=0.2,
        ge=0,
        le=2,
    )

    rag_generation_profile: ProcessingProfile = (
    ProcessingProfile.CLOUD
    )

    laravel_internal_base_url: str | None = None
    processing_callback_secret: SecretStr | None = None
    processing_callback_timeout_seconds: float = Field(
        default=5.0,
        gt=0,
        le=30,
    )
    processing_callback_max_attempts: int = Field(
        default=3,
        ge=1,
        le=5,
    )
    processing_callback_retry_delay_seconds: float = Field(
        default=0.25,
        ge=0,
        le=5,
    )

    qdrant_url: str = "http://127.0.0.1:6333"
    qdrant_cloud_collection: str = "rag_documents_cloud"
    qdrant_hybrid_local_collection: str = "rag_documents_hybrid_local"

    rag_retrieval_top_k: int = Field(
        default=5,
        ge=1,
    )
    rag_rrf_candidate_multiplier: int = Field(
        default=2,
        ge=1,
    )
    rag_rerank_candidate_multiplier: int = Field(
        default=2,
        ge=1,
    )
    rag_cross_profile_rrf_k: int = Field(
        default=60,
        ge=1,
    )

    chunk_size: int = 800
    chunk_overlap: int = 80

    jinaai_api_key: SecretStr = SecretStr("")
    cloud_embed_model: str = "jina-embeddings-v3"
    cloud_rerank_model: str = "jina-reranker-v2-base-multilingual"
    embed_batch_size: int = Field(default=6, ge=1)
    wait_between_batches: float = Field(default=3, ge=0)
    rate_limit_retry_wait: float = Field(default=30, ge=0)
    max_retries: int = Field(default=5, ge=0)

    local_embed_model: str = "BAAI/bge-m3"
    local_rerank_model: str = "BAAI/bge-reranker-v2-m3"

    local_min_available_memory_ratio: float = Field(
        default=0.15,
        gt=0,
        lt=1,
    )
    local_system_reserve_gib: float = Field(default=2.0, ge=1, le=16)
    local_embedding_headroom_gib: float = Field(default=2.0, gt=0, le=32)
    local_reranker_headroom_gib: float = Field(default=2.0, gt=0, le=32)
    local_llm_headroom_gib: float = Field(default=4.0, gt=0, le=64)

    local_ai_max_concurrency: int = Field(
        default=1,
        ge=1,
        le=1,
    )
    local_embed_keep_alive_seconds: float = Field(
        default=0,
        ge=0,
    )
    local_rerank_keep_alive_seconds: float = Field(
        default=0,
        ge=0,
    )



def validate_startup_configuration(settings: Settings) -> None:
    if "rag_deployment_mode" not in settings.model_fields_set:
        raise StartupConfigurationError(
            "RAG_DEPLOYMENT_MODE must be explicitly configured."
        )

    if (
        settings.internal_api_key is None
        or not settings.internal_api_key.get_secret_value().strip()
    ):
        raise StartupConfigurationError(
            "INTERNAL_API_KEY is required and must not be blank."
        )

    if settings.rag_deployment_mode is DeploymentMode.CLOUD:
        if settings.local_ai_topology is not None:
            raise StartupConfigurationError(
                "LOCAL_AI_TOPOLOGY must not be configured "
                "when RAG_DEPLOYMENT_MODE=cloud."
            )

        return

    if settings.local_ai_topology is None:
        raise StartupConfigurationError(
            "LOCAL_AI_TOPOLOGY is required "
            "when RAG_DEPLOYMENT_MODE=local."
        )


@lru_cache
def get_settings() -> Settings:
    return Settings()

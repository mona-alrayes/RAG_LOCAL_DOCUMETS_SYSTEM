from pydantic import SecretStr

from app.core.config import DeploymentMode, Settings
from app.runtime.models import LocalRuntimeSnapshot
from app.schemas.capabilities import (
    DeploymentCapabilitiesResponse,
    ProviderCapability,
    ProviderStatus,
)
from app.schemas.runtime import LocalRuntimeCapability

SHARED_PROVIDERS = (
    "llama_parse",
)

CLOUD_PROFILE_PROVIDERS = (
    "jina_embeddings",
    "jina_reranker",
    "hugging_face_llm",
)

HYBRID_LOCAL_PROFILE_PROVIDERS = (
    "bge_m3_embeddings",
    "bge_reranker",
    "ollama_llm",
)


class CapabilitiesService:
    """
    Builds the current AI processing capabilities.

    supported_profiles تحدد ما يسمح به نوع الـdeployment،
    بينما available_profiles تحدد ما يمكن تشغيله فعليًا الآن
    حسب الإعدادات وحالة الـlocal runtime.
    """

    def build(
        self,
        settings: Settings,
        local_runtime_snapshot: LocalRuntimeSnapshot | None = None,
    ) -> DeploymentCapabilitiesResponse:
        supported_profiles = self._supported_profiles(
            settings.rag_deployment_mode,
        )

        available_profiles = self._available_profiles(
            settings,
            local_runtime_snapshot,
        )

        if settings.rag_deployment_mode is DeploymentMode.CLOUD:
            provider_names = (
                SHARED_PROVIDERS + CLOUD_PROFILE_PROVIDERS
            )
        else:
            provider_names = (
                SHARED_PROVIDERS
                + CLOUD_PROFILE_PROVIDERS
                + HYBRID_LOCAL_PROFILE_PROVIDERS
            )

        return DeploymentCapabilitiesResponse(
            deployment_mode=settings.rag_deployment_mode,
            supported_profiles=supported_profiles,
            available_profiles=available_profiles,
            providers=self._providers(
                provider_names,
                settings,
                local_runtime_snapshot,
            ),
            local_runtime=(
                None
                if settings.rag_deployment_mode is DeploymentMode.CLOUD
                else self._local_runtime(local_runtime_snapshot)
            ),
        )

    def _supported_profiles(
        self,
        deployment_mode: DeploymentMode,
    ) -> list[str]:
        """
        يحدد الـprofiles المدعومة معماريًا حسب deployment mode.
        """

        if deployment_mode is DeploymentMode.CLOUD:
            return ["cloud"]

        return ["cloud", "hybrid_local"]

    def _available_profiles(
        self,
        settings: Settings,
        local_runtime_snapshot: LocalRuntimeSnapshot | None,
    ) -> list[str]:
        """
        يحدد الـprofiles القابلة لبدء processing فعليًا الآن.
        """

        available_profiles: list[str] = []

        shared_parser_available = self._has_secret(
            settings.llama_cloud_api_key,
        )

        cloud_embeddings_available = self._has_secret(
            settings.jinaai_api_key,
        )

        if (
            shared_parser_available
            and cloud_embeddings_available
        ):
            available_profiles.append("cloud")

        local_runtime_available = (
            local_runtime_snapshot is not None
            and local_runtime_snapshot.ready
        )

        if (
            settings.rag_deployment_mode is DeploymentMode.LOCAL
            and shared_parser_available
            and local_runtime_available
        ):
            available_profiles.append("hybrid_local")

        return available_profiles

    def _providers(
        self,
        provider_names: tuple[str, ...],
        settings: Settings,
        local_runtime_snapshot: LocalRuntimeSnapshot | None,
    ) -> list[ProviderCapability]:
        """
        يبني الحالة المختصرة للـproviders التي يمكن التحقق منها بأمان.
        """

        llama_parse_status = (
            ProviderStatus.AVAILABLE
            if self._has_secret(settings.llama_cloud_api_key)
            else ProviderStatus.UNAVAILABLE
        )

        jina_status = (
            ProviderStatus.AVAILABLE
            if self._has_secret(settings.jinaai_api_key)
            else ProviderStatus.UNAVAILABLE
        )

        hugging_face_status = (
            ProviderStatus.AVAILABLE
            if self._has_secret(settings.hf_token)
            else ProviderStatus.UNAVAILABLE
        )

        ollama_status = (
            ProviderStatus.AVAILABLE
            if (
                settings.ollama_base_url.strip()
                and settings.local_llm_model.strip()
            )
            else ProviderStatus.UNAVAILABLE
        )

        local_runtime_status = (
            ProviderStatus.AVAILABLE
            if (
                local_runtime_snapshot is not None
                and local_runtime_snapshot.ready
            )
            else ProviderStatus.UNAVAILABLE
        )

        status_by_provider = {
            "llama_parse": llama_parse_status,
            "jina_embeddings": jina_status,
            "jina_reranker": jina_status,
            "hugging_face_llm": hugging_face_status,
            "ollama_llm": ollama_status,
            "bge_m3_embeddings": local_runtime_status,
            "bge_reranker": local_runtime_status,
        }

        return [
            ProviderCapability(
                provider=provider_name,
                status=status_by_provider.get(
                    provider_name,
                    ProviderStatus.NOT_CHECKED,
                ),
            )
            for provider_name in provider_names
        ]

    def _local_runtime(
        self,
        snapshot: LocalRuntimeSnapshot | None,
    ) -> LocalRuntimeCapability | None:
        """
        يحول internal runtime snapshot إلى API capability representation.
        """

        if snapshot is None:
            return None

        return LocalRuntimeCapability(
            ready=snapshot.ready,
            requested_device=snapshot.requested_device,
            selected_backend=snapshot.selected_backend,
            selected_dtype=snapshot.selected_dtype,
            probe_status=snapshot.probe_status,
            failure_reason=snapshot.failure_reason,
        )

    def _has_secret(self, secret: SecretStr | None) -> bool:
        """
        يتحقق من وجود credential فعلية بدون كشف قيمتها.
        """

        return (
            secret is not None
            and bool(secret.get_secret_value().strip())
        )
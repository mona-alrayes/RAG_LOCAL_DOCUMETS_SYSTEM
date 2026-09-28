from pathlib import Path
import builtins
import subprocess
import sys
import textwrap
import tomllib

from app.core.config import DeploymentMode, Settings
from app.runtime.startup import initialize_local_runtime


PROJECT_ROOT = Path(__file__).resolve().parents[1]
PYPROJECT_PATH = PROJECT_ROOT / "pyproject.toml"

FORBIDDEN_CLOUD_DEPENDENCIES = {
    "torch",
    "transformers",
    "fastembed",
    "ollama",
}


def _dependency_name(requirement: str) -> str:
    return (
        requirement.split("[", 1)[0]
        .split("=", 1)[0]
        .split("<", 1)[0]
        .split(">", 1)[0]
        .split("~", 1)[0]
        .strip()
        .lower()
    )


def test_base_dependencies_exclude_local_ai_packages() -> None:
    with PYPROJECT_PATH.open("rb") as file:
        pyproject = tomllib.load(file)

    dependencies = pyproject["project"]["dependencies"]
    dependency_names = {_dependency_name(item) for item in dependencies}

    assert dependency_names.isdisjoint(FORBIDDEN_CLOUD_DEPENDENCIES)


def test_local_native_extra_contains_local_ai_dependencies() -> None:
    with PYPROJECT_PATH.open("rb") as file:
        pyproject = tomllib.load(file)

    local_dependencies = pyproject["project"]["optional-dependencies"]["local-native"]
    dependency_names = {_dependency_name(item) for item in local_dependencies}

    assert "transformers" in dependency_names
    assert "fastembed" in dependency_names


def test_cloud_import_path_does_not_load_local_ai_packages() -> None:
    script = textwrap.dedent(
        """
        import importlib.abc
        import sys


        BLOCKED_PACKAGES = {"torch", "transformers", "fastembed"}


        class BlockLocalAiImports(importlib.abc.MetaPathFinder):
            def find_spec(self, fullname, path=None, target=None):
                if fullname.split(".", 1)[0] in BLOCKED_PACKAGES:
                    raise ImportError(
                        f"Cloud import path attempted to load {fullname}"
                    )

                return None


        sys.meta_path.insert(0, BlockLocalAiImports())

        import app.processing.base
        import app.processing.registry
        import app.processing.cloud_chunking
        import app.processing.cloud_embeddings
        import app.processing.cloud_sparse
        import app.processing.reporting
        """
    )

    result = subprocess.run(
        [sys.executable, "-c", script],
        cwd=PROJECT_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )

    assert result.returncode == 0, result.stderr


def test_cloud_runtime_initialization_skips_local_runtime_components(
    monkeypatch,
) -> None:
    blocked_modules = {
        "app.runtime.resolver",
        "app.runtime.telemetry",
        "app.runtime.torch_runtime",
    }
    original_import = builtins.__import__

    def guarded_import(
        name,
        globals=None,
        locals=None,
        fromlist=(),
        level=0,
    ):
        if name in blocked_modules:
            raise AssertionError(
                f"Cloud runtime attempted to import local component: {name}"
            )

        return original_import(
            name,
            globals,
            locals,
            fromlist,
            level,
        )

    monkeypatch.setattr(builtins, "__import__", guarded_import)

    initialize_local_runtime(
        Settings(
            rag_deployment_mode=DeploymentMode.CLOUD,
        )
    )

from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path

import pytest


SCRIPT_PATH = (
    Path(__file__).resolve().parents[1]
    / "scripts"
    / "install_local_native.py"
)


def _load_installer():
    spec = spec_from_file_location(
        "install_local_native",
        SCRIPT_PATH,
    )
    assert spec is not None
    assert spec.loader is not None

    module = module_from_spec(spec)
    spec.loader.exec_module(module)

    return module


@pytest.mark.parametrize(
    ("gpu_names", "expected_backend"),
    [
        ("NVIDIA GeForce RTX 4070", "cuda"),
        ("Intel Arc Graphics", "xpu"),
        ("", "cpu"),
    ],
)
def test_windows_detects_supported_backend(
    monkeypatch,
    gpu_names,
    expected_backend,
) -> None:
    installer = _load_installer()

    monkeypatch.setattr(
        installer,
        "_windows_gpu_names",
        lambda: gpu_names.lower(),
    )

    backend = installer._detect_windows_backend()

    assert backend.value == expected_backend


def test_windows_rejects_amd_rocm_bootstrap(
    monkeypatch,
) -> None:
    installer = _load_installer()

    monkeypatch.setattr(
        installer,
        "_windows_gpu_names",
        lambda: "amd radeon graphics",
    )

    with pytest.raises(
        RuntimeError,
        match="ROCm",
    ):
        installer._detect_windows_backend()


@pytest.mark.parametrize(
    ("vendor_ids", "expected_backend"),
    [
        ({"0x10de"}, "cuda"),
        ({"0x1002"}, "rocm"),
        ({"0x8086"}, "xpu"),
        (set(), "cpu"),
    ],
)
def test_linux_detects_gpu_vendor(
    monkeypatch,
    vendor_ids,
    expected_backend,
) -> None:
    installer = _load_installer()

    monkeypatch.setattr(
        installer,
        "_linux_gpu_vendor_ids",
        lambda: vendor_ids,
    )
    monkeypatch.setattr(
        installer,
        "_command_output",
        lambda *arguments: "",
    )

    backend = installer._detect_linux_backend()

    assert backend.value == expected_backend


def test_apple_silicon_detects_mps(
    monkeypatch,
) -> None:
    installer = _load_installer()

    monkeypatch.setattr(
        installer.sys,
        "platform",
        "darwin",
    )
    monkeypatch.setattr(
        installer.platform,
        "machine",
        lambda: "arm64",
    )

    backend = installer._detect_torch_backend()

    assert backend is installer.TorchBackend.MPS


@pytest.mark.parametrize(
    ("backend_name", "platform_name", "expected_arguments"),
    [
        (
            "cuda",
            "win32",
            (
                "install",
                "torch",
                "--index-url",
                "https://download.pytorch.org/whl/cu130",
            ),
        ),
        (
            "rocm",
            "linux",
            (
                "install",
                "torch",
                "--index-url",
                "https://download.pytorch.org/whl/rocm7.2",
            ),
        ),
        (
            "xpu",
            "win32",
            (
                "install",
                "torch",
                "--index-url",
                "https://download.pytorch.org/whl/xpu",
            ),
        ),
        (
            "mps",
            "darwin",
            (
                "install",
                "torch",
            ),
        ),
        (
            "cpu",
            "linux",
            (
                "install",
                "torch",
                "--index-url",
                "https://download.pytorch.org/whl/cpu",
            ),
        ),
    ],
)
def test_installs_torch_for_selected_backend(
    monkeypatch,
    backend_name,
    platform_name,
    expected_arguments,
) -> None:
    installer = _load_installer()
    calls = []

    monkeypatch.setattr(
        installer.sys,
        "platform",
        platform_name,
    )
    monkeypatch.setattr(
        installer,
        "_run_pip",
        lambda *arguments: calls.append(arguments),
    )

    backend = installer.TorchBackend(backend_name)

    installer._install_torch(backend)

    assert calls == [expected_arguments]

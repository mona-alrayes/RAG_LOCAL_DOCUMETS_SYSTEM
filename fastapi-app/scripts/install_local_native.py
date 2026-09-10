from enum import StrEnum
from pathlib import Path
import platform
import subprocess
import sys


PROJECT_ROOT = Path(__file__).resolve().parents[1]

PYTORCH_CUDA_INDEX_URL = "https://download.pytorch.org/whl/cu130"
PYTORCH_ROCM_INDEX_URL = "https://download.pytorch.org/whl/rocm7.2"
PYTORCH_XPU_INDEX_URL = "https://download.pytorch.org/whl/xpu"
PYTORCH_CPU_INDEX_URL = "https://download.pytorch.org/whl/cpu"


class TorchBackend(StrEnum):
    CUDA = "cuda"
    ROCM = "rocm"
    XPU = "xpu"
    MPS = "mps"
    CPU = "cpu"


def _run_pip(*arguments: str) -> None:
    subprocess.run(
        [sys.executable, "-m", "pip", *arguments],
        cwd=PROJECT_ROOT,
        check=True,
    )


def _command_output(*arguments: str) -> str:
    try:
        result = subprocess.run(
            arguments,
            capture_output=True,
            text=True,
            check=False,
            timeout=10,
        )
    except (OSError, subprocess.SubprocessError):
        return ""

    if result.returncode != 0:
        return ""

    return result.stdout.strip()


def _windows_gpu_names() -> str:
    command = (
        "Get-CimInstance Win32_VideoController "
        "| Select-Object -ExpandProperty Name"
    )

    for executable in ("powershell", "pwsh"):
        output = _command_output(
            executable,
            "-NoProfile",
            "-Command",
            command,
        )

        if output:
            return output.lower()

    return ""


def _linux_gpu_vendor_ids() -> set[str]:
    vendor_ids: set[str] = set()

    for path in Path("/sys/class/drm").glob("card*/device/vendor"):
        try:
            vendor_ids.add(
                path.read_text(
                    encoding="ascii",
                    errors="ignore",
                ).strip().lower()
            )
        except OSError:
            continue

    return vendor_ids


def _detect_linux_backend() -> TorchBackend:
    vendor_ids = _linux_gpu_vendor_ids()

    if "0x10de" in vendor_ids:
        return TorchBackend.CUDA

    if "0x1002" in vendor_ids:
        return TorchBackend.ROCM

    if "0x8086" in vendor_ids:
        return TorchBackend.XPU

    if _command_output("nvidia-smi", "-L"):
        return TorchBackend.CUDA

    pci_devices = _command_output("lspci").lower()

    if "nvidia" in pci_devices:
        return TorchBackend.CUDA

    if "amd" in pci_devices or "radeon" in pci_devices:
        return TorchBackend.ROCM

    if "intel" in pci_devices:
        return TorchBackend.XPU

    return TorchBackend.CPU


def _detect_windows_backend() -> TorchBackend:
    gpu_names = _windows_gpu_names()

    if "nvidia" in gpu_names:
        return TorchBackend.CUDA

    if "amd" in gpu_names or "radeon" in gpu_names:
        raise RuntimeError(
            "AMD GPU detected on Windows, but the ROCm PyTorch "
            "build used by this project requires Linux."
        )

    if "intel" in gpu_names:
        return TorchBackend.XPU

    return TorchBackend.CPU


def _detect_torch_backend() -> TorchBackend:
    if sys.platform == "darwin":
        if platform.machine().lower() in {"arm64", "aarch64"}:
            return TorchBackend.MPS

        return TorchBackend.CPU

    if sys.platform == "win32":
        return _detect_windows_backend()

    if sys.platform.startswith("linux"):
        return _detect_linux_backend()

    raise RuntimeError(
        f"Local-native bootstrap is not supported on platform: {sys.platform}"
    )


def _install_torch(backend: TorchBackend) -> None:
    if backend is TorchBackend.CUDA:
        _run_pip(
            "install",
            "torch",
            "--index-url",
            PYTORCH_CUDA_INDEX_URL,
        )
        return

    if backend is TorchBackend.ROCM:
        if not sys.platform.startswith("linux"):
            raise RuntimeError(
                "ROCm PyTorch installation is supported only on Linux."
            )

        _run_pip(
            "install",
            "torch",
            "--index-url",
            PYTORCH_ROCM_INDEX_URL,
        )
        return

    if backend is TorchBackend.XPU:
        _run_pip(
            "install",
            "torch",
            "--index-url",
            PYTORCH_XPU_INDEX_URL,
        )
        return

    if backend is TorchBackend.MPS:
        if sys.platform != "darwin":
            raise RuntimeError(
                "MPS PyTorch installation is supported only on macOS."
            )

        _run_pip("install", "torch")
        return

    if backend is TorchBackend.CPU:
        if sys.platform == "darwin":
            _run_pip("install", "torch")
            return

        _run_pip(
            "install",
            "torch",
            "--index-url",
            PYTORCH_CPU_INDEX_URL,
        )
        return

    raise RuntimeError(
        f"Unsupported Torch backend: {backend.value}"
    )


def main() -> None:
    backend = _detect_torch_backend()

    print(f"Detected local Torch backend: {backend.value}")

    _install_torch(backend)
    _run_pip("install", ".[local-native]")


if __name__ == "__main__":
    main()
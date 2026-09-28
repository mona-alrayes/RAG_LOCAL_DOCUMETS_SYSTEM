import subprocess
import sys
from pathlib import Path


def test_second_runtime_is_rejected_until_first_releases(tmp_path):
    from app.runtime.process_guard import LocalProcessGuard

    path = tmp_path / "runtime.lock"
    program = """
import sys
from app.runtime.process_guard import LocalProcessGuard
from app.core.exceptions import ApplicationException
try:
    with LocalProcessGuard(sys.argv[1]):
        print('acquired')
except ApplicationException as error:
    print(error.code)
"""

    def run():
        return subprocess.check_output(
            [sys.executable, "-c", program, str(path)],
            cwd=Path(__file__).resolve().parents[1],
            text=True,
        ).strip()

    with LocalProcessGuard(path):
        assert run() == "local_runtime_already_running"
    assert run() == "acquired"

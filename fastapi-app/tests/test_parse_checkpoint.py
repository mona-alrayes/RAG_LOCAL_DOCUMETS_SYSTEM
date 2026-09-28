import pytest
from app.core.exceptions import ApplicationException
from app.parsing.checkpoint import ParseCheckpointStore
from app.parsing.providers.llamaparse import LlamaParsePage


def test_completed_parse_survives_restart_and_manual_retry(tmp_path):
    file = tmp_path / "input.txt"
    file.write_text("content")
    calls = []

    def load():
        calls.append(1)
        return [LlamaParsePage(1, "parsed content")]

    for run in (1, 1, 2):
        store = ParseCheckpointStore(tmp_path / "private")
        pages = store.load(
            user_id=1,
            document_id=2,
            run_id=run,
            file_path=file,
            parser_signature="v1",
            loader=load,
        )
        assert pages[0].markdown == "parsed content"
    assert len(calls) == 1
    store.delete_run(user_id=1, document_id=2, run_id=1)
    assert list((tmp_path / "private").rglob("*.json"))
    store.delete_run(user_id=1, document_id=2, run_id=2)
    assert not list((tmp_path / "private").rglob("*.json"))


def test_uncertain_request_is_not_submitted_again(tmp_path):
    file = tmp_path / "input.txt"
    file.write_text("content")
    store = ParseCheckpointStore(tmp_path / "private")
    calls = []

    def load():
        calls.append(1)
        raise TimeoutError("unknown remote outcome")

    args = {
        "user_id": 1,
        "document_id": 2,
        "run_id": 1,
        "file_path": file,
        "parser_signature": "v1",
        "loader": load,
    }
    with pytest.raises(TimeoutError):
        store.load(**args)
    with pytest.raises(ApplicationException) as error:
        store.load(**args)
    assert error.value.code == "document_parsing_outcome_unknown"
    assert len(calls) == 1


def test_interrupted_resumable_parse_reuses_persisted_job_id(tmp_path):
    file = tmp_path / "input.txt"
    file.write_text("content")
    submissions = []
    resumptions = []

    def submit():
        submissions.append("job-123")
        return "job-123"

    def resume(job_id):
        resumptions.append(job_id)
        if len(resumptions) == 1:
            raise TimeoutError("temporary polling interruption")
        return [LlamaParsePage(1, "parsed content")]

    args = {
        "user_id": 1,
        "document_id": 2,
        "run_id": 1,
        "file_path": file,
        "parser_signature": "v1",
        "submitter": submit,
        "resumer": resume,
    }

    with pytest.raises(TimeoutError):
        ParseCheckpointStore(tmp_path / "private").load(**args)

    pages = ParseCheckpointStore(tmp_path / "private").load(**args)

    assert pages == [LlamaParsePage(1, "parsed content")]
    assert submissions == ["job-123"]
    assert resumptions == ["job-123", "job-123"]


@pytest.mark.parametrize(
    "change", ["user_id", "document_id", "parser_signature", "content"]
)
def test_checkpoint_scope_prevents_stale_or_cross_user_reuse(tmp_path, change):
    file = tmp_path / "input.txt"
    file.write_text("content")
    store = ParseCheckpointStore(tmp_path / "private")
    calls = []

    def load():
        calls.append(1)
        return [LlamaParsePage(1, "parsed")]

    args = {
        "user_id": 1,
        "document_id": 2,
        "run_id": 1,
        "file_path": file,
        "parser_signature": "v1",
        "loader": load,
    }
    store.load(**args)
    if change == "content":
        file.write_text("changed")
    else:
        args[change] = "v2" if change == "parser_signature" else 9
    store.load(**args)
    assert len(calls) == 2

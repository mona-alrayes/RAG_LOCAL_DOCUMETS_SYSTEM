from app.processing.chunks import NormalizedChunk
from app.processing.markdown_chunking import MarkdownChunker

# نحافظ على الاستيراد القديم لـ NormalizedChunk للتوافق مع المستهلكين الحاليين.
__all__ = ["CloudChunker", "NormalizedChunk"]

class CloudChunker(MarkdownChunker):
    # نفس التقطيع في المسارين حتى تكون المقارنة مستقلة عن مزوّد التضمين.
    pass

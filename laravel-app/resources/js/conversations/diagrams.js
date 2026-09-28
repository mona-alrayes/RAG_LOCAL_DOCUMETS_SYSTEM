import DOMPurify from 'dompurify';

let enginePromise;
let nextId = 0;
const MAX_DIAGRAMS = 3;

const loadEngine = () => {
    // تحميل كسول محلي: الرسائل العادية لا تحتاج تنزيل محرك الرسم.
    enginePromise ??= import('mermaid').then(({ default: mermaid }) => {
        mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            suppressErrorRendering: true,
            maxTextSize: 12000,
            maxEdges: 100,
            layout: 'dagre',
            theme: 'dark',
            fontFamily: 'Arial, sans-serif',
            htmlLabels: false,
            flowchart: { htmlLabels: false, useMaxWidth: false },
        });
        return mermaid;
    });
    return enginePromise;
};

const allowed = (source) => (
    source.length <= 12000
    && source.split('\n').length <= 150
    && /^(?:(?:graph|flowchart)\s+(?:TD|TB|BT|LR|RL)\b|timeline\b)/.test(source.trim())
    // لا نسمح بإعدادات أو HTML أو تفاعلات يكتبها الموديل؛ فقط وصف الرسم البسيط.
    && !/%%\{|^\s*---|(?:^|[;\n])\s*(?:click|style|classDef|linkStyle)\b|@\{|<\s*\/?[a-z]|https?:\/\/|!\[|url\s*\(/im.test(source)
);

const renderOne = async (root, code, index) => {
    const source = code.textContent;
    const pre = code.parentElement;
    const card = document.createElement('section');
    card.dataset.conversationDiagram = '';
    card.className = 'conversation-diagram';
    const status = document.createElement('p');
    status.setAttribute('role', 'status');
    status.textContent = 'جاري رسم المخطط…';
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = 'عرض كود المخطط';
    const copy = document.createElement('button');
    copy.type = 'button';
    copy.dataset.copyDiagram = '';
    copy.setAttribute('aria-live', 'polite');
    copy.textContent = 'نسخ الكود';
    pre.replaceWith(card);
    details.append(summary, pre, copy);
    card.append(status, details);

    try {
        if (index >= MAX_DIAGRAMS || !allowed(source)) throw new Error('Unsupported diagram');
        const engine = await loadEngine();
        if (!root.contains(card)) return;
        // القياس يحتاج عنصراً متصلاً بالصفحة، لكن نمنع بقايا أخطاء المحرك من الظهور.
        const staging = document.createElement('div');
        staging.className = 'conversation-diagram-staging';
        staging.setAttribute('aria-hidden', 'true');
        document.body.append(staging);
        let svg;
        try {
            ({ svg } = await engine.render(`conversation-diagram-${++nextId}`, source, staging));
        } finally {
            staging.remove();
        }
        if (!root.contains(card)) return;
        const clean = DOMPurify.sanitize(svg, {
            USE_PROFILES: { svg: true, svgFilters: true },
            FORBID_TAGS: ['script', 'foreignObject', 'image', 'a'],
            FORBID_ATTR: ['href', 'xlink:href'],
        });
        const parsed = new DOMParser().parseFromString(clean, 'image/svg+xml');
        const bounds = parsed.documentElement.getAttribute('viewBox')?.split(/[\s,]+/).map(Number);
        if (parsed.documentElement.localName !== 'svg' || !bounds || bounds.length !== 4 || !bounds.every(Number.isFinite)) {
            throw new Error('Invalid rendered diagram');
        }
        const height = Math.max(180, Math.min(650, bounds[3] + 32));
        const frame = document.createElement('iframe');
        frame.title = 'مخطط توضيحي من الإجابة';
        frame.setAttribute('sandbox', '');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.height = String(height);
        // الرسم داخل إطار بلا سكربتات ولا اتصال خارجي، مع تنقية SVG كطبقة حماية إضافية.
        frame.srcdoc = `<!doctype html><html lang="ar"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'"><style>body{margin:0;padding:12px;background:#101a2b;color:#eee;font-family:Arial,sans-serif}svg{display:block;max-width:none;margin:auto}svg text{font-family:Arial,sans-serif}</style></head><body>${clean}</body></html>`;
        card.insertBefore(frame, details);
        status.textContent = 'مخطط توضيحي — راجع الشرح والمصادر للتحقق من العلاقات.';
    } catch {
        if (!root.contains(card)) return;
        status.textContent = 'تعذّر عرض المخطط: الصيغة غير صالحة أو النوع/الحجم غير مدعوم في هذه التجربة.';
        details.open = true;
    }
};

export const renderConversationDiagrams = (root) => {
    // الموديل المحلي قد ينسى سياج timeline؛ نقبل فقرة مستقلة ذات أسطر أحداث واضحة فقط.
    // لا نصلح العلاقات ولا ندمج نصوصاً متفرقة، ونسخ الرسالة يبقى من المصدر الأصلي.
    for (const paragraph of root.querySelectorAll('p')) {
        if (paragraph.closest('[data-conversation-diagram]') || paragraph.children.length) continue;
        const source = paragraph.textContent;
        const lines = source.trim().split('\n').map((line) => line.trim());
        if (lines[0] !== 'timeline' || lines.length < 2 || !lines.slice(1).every((line) => /^(?:title\s+.+|section\s+.+|[^:]+\s*:\s*.+)$/.test(line))) continue;
        const pre = document.createElement('pre');
        const code = document.createElement('code');
        code.className = 'language-mermaid';
        code.textContent = source;
        pre.append(code);
        paragraph.replaceWith(pre);
    }
    return Promise.all([...root.querySelectorAll('pre > code.language-mermaid')]
        .filter((code) => !code.closest('[data-conversation-diagram]'))
        .map((code, index) => renderOne(root, code, index)));
};

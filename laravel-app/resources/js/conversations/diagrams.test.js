import { beforeEach, describe, expect, it, vi } from 'vitest';

const engine = vi.hoisted(() => ({ initialize: vi.fn(), render: vi.fn() }));
vi.mock('mermaid', () => ({ default: engine }));
import { renderConversationDiagrams } from './diagrams.js';

const message = (source) => {
    const root = document.createElement('div');
    const pre = document.createElement('pre');
    const code = document.createElement('code');
    code.className = 'language-mermaid';
    code.textContent = source;
    pre.append(code);
    root.append(pre);
    document.body.append(root);
    return root;
};

describe('safe conversation diagrams', () => {
    beforeEach(() => {
        document.body.replaceChildren();
        engine.render.mockReset();
        engine.render.mockResolvedValue({ svg: '<svg viewBox="0 0 300 180"><text>مدير الجامعة</text></svg>' });
    });

    it('renders a standalone bare timeline from the local model without changing its events', async () => {
        const root = document.createElement('div');
        const paragraph = document.createElement('p');
        paragraph.textContent = 'timeline\n2020 : بدأ المشروع\n2022 : بدأت التجربة';
        root.append(paragraph);
        document.body.append(root);
        await renderConversationDiagrams(root);
        expect(root.querySelector('iframe')).not.toBeNull();
        expect(root.querySelector('code').textContent).toBe(paragraph.textContent);
    });

    it('does not reinterpret mentions of timeline or prose as diagram syntax', async () => {
        const root = document.createElement('div');
        root.innerHTML = '<p>timeline is a diagram type</p><p>timeline\nهذا شرح وليس تعريف أحداث.</p>';
        await renderConversationDiagrams(root);
        expect(engine.render).not.toHaveBeenCalled();
    });

    it.each(['graph TD\nA["مدير"] --> B["كلية"]', 'flowchart LR\nA --> B', 'timeline\n2020 : البداية'])('renders supported syntax and preserves source: %s', async (source) => {
        const root = message(source);
        await renderConversationDiagrams(root);
        const frame = root.querySelector('iframe');
        expect(frame?.getAttribute('sandbox')).toBe('');
        expect(frame?.srcdoc).toContain('مدير الجامعة');
        expect(frame?.srcdoc).toContain("default-src 'none'");
        expect(root.querySelector('details code').textContent).toBe(source);
        expect(root.querySelector('[data-copy-diagram]')).not.toBeNull();
    });

    it.each(['sequenceDiagram\nA->>B: Hi', 'graph TD\n%%{init: {"securityLevel":"loose"}}%%\nA-->B', 'graph TD\nclick A "https://evil.test"', 'graph TD\nA["<img src=x>"]', 'graph TD; A-->B; classDef custom fill:red', 'graph TD\nA["![image](//external.test/image)"]', 'graph TD\n' + 'A'.repeat(13000)])('keeps unsafe, unsupported or excessive input as code', async (source) => {
        const root = message(source);
        await renderConversationDiagrams(root);
        expect(engine.render).not.toHaveBeenCalled();
        expect(root.querySelector('[role="status"]').textContent).toContain('تعذّر');
        expect(root.querySelector('code').textContent).toBe(source);
    });

    it('preserves code after parser failure without exposing engine errors', async () => {
        engine.render.mockRejectedValue(new Error('sensitive parser details'));
        const root = message('graph TD\ninvalid [[[ ');
        await renderConversationDiagrams(root);
        expect(root.querySelector('iframe')).toBeNull();
        expect(root.textContent).toContain('تعذّر');
        expect(root.textContent).not.toContain('sensitive parser details');
        expect(root.querySelector('details').open).toBe(true);
    });

    it('sanitizes generated SVG and disallows external resources in its isolated frame', async () => {
        engine.render.mockResolvedValue({ svg: '<svg viewBox="0 0 10 10"><script>alert(1)</script><image href="https://evil.test/x"/><foreignObject>html</foreignObject><text onclick="alert(1)">safe</text></svg>' });
        const root = message('graph TD\nA-->B');
        await renderConversationDiagrams(root);
        const html = root.querySelector('iframe').srcdoc;
        expect(html).not.toMatch(/<script|<image|foreignObject|onclick|evil\.test/);
    });

    it('does not revive an old message after an asynchronous render', async () => {
        let finish;
        engine.render.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        const root = message('graph TD\nA-->B');
        const task = renderConversationDiagrams(root);
        await vi.waitFor(() => expect(finish).toBeTypeOf('function'));
        root.textContent = 'New answer';
        finish({ svg: '<svg viewBox="0 0 10 10"></svg>' });
        await task;
        expect(root.textContent).toBe('New answer');
        expect(root.querySelector('iframe')).toBeNull();
    });
});

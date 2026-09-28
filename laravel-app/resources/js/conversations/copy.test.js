import { beforeEach, expect, it, vi } from 'vitest';
import { copyConversationText } from './copy.js';

beforeEach(() => { document.body.replaceChildren(); });

it('copies original message text, not source drawers or controls', async () => {
    document.body.innerHTML = '<article data-conversation-message="1"><div data-message-copy-source="**جواب**"></div><button data-copy-message aria-label="نسخ الرسالة"><svg data-copy-icon></svg><svg data-copy-success-icon hidden></svg><span data-copy-feedback>نسخ الرسالة</span></button><aside>مصادر وتوقيت</aside></article>';
    const writeText = vi.fn().mockResolvedValue();
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    const button = document.querySelector('button');
    await copyConversationText(button);
    expect(writeText).toHaveBeenCalledWith('**جواب**');
    expect(button.getAttribute('aria-label')).toBe('تم النسخ');
    expect(button.querySelector('[data-copy-icon]').hidden).toBe(true);
    expect(button.querySelector('[data-copy-success-icon]').hidden).toBe(false);
    expect(button.querySelector('[data-copy-feedback]').textContent).toBe('تم النسخ');
});

it('reports clipboard denial without pretending copying succeeded', async () => {
    document.body.innerHTML = '<article data-conversation-message="1"><div data-message-copy-source="answer"></div><button data-copy-message aria-label="نسخ الرسالة"><svg data-copy-icon></svg><svg data-copy-success-icon hidden></svg><span data-copy-feedback>نسخ الرسالة</span></button></article>';
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
    const button = document.querySelector('button');
    await copyConversationText(button);
    expect(button.getAttribute('aria-label')).toContain('تعذّر النسخ');
    expect(button.querySelector('[data-copy-icon]').hidden).toBe(false);
    expect(button.querySelector('[data-copy-success-icon]').hidden).toBe(true);
    expect(button.querySelector('[data-copy-feedback]').textContent).toContain('تعذّر النسخ');
    expect(button.disabled).toBe(false);
});

it('copies only the diagram source when its own copy button is used', async () => {
    document.body.innerHTML = '<section data-conversation-diagram><code>graph TD\nA-->B</code><button data-copy-diagram>نسخ الكود</button></section>';
    const writeText = vi.fn().mockResolvedValue();
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    await copyConversationText(document.querySelector('button'));
    expect(writeText).toHaveBeenCalledWith('graph TD\nA-->B');
});

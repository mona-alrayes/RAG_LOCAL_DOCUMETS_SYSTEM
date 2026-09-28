import {
    afterAll,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    initializeConversationComposer,
    initializeConversationUi,
    renderConversationMarkdown,
    startConversationAnswerStream,
} from './chat.js';

const diagrams = vi.hoisted(() => ({ render: vi.fn(() => Promise.resolve()) }));
vi.mock('./diagrams.js', () => ({ renderConversationDiagrams: diagrams.render }));

class FakeEventSource {
    static instances = [];

    constructor(url) {
        this.url = url;
        this.listeners = new Map();
        this.closed = false;

        FakeEventSource.instances.push(this);
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    emit(type, data = '') {
        const listener = this.listeners.get(type);

        listener?.({
            data,
        });
    }

    close() {
        this.closed = true;
    }
}

const composer = () => {
    document.body.innerHTML = `
        <form data-conversation-composer>
            <textarea data-conversation-question></textarea>
            <button
                type="submit"
                data-conversation-submit
            >
                إرسال
            </button>
        </form>
    `;

    const form = document.querySelector(
        '[data-conversation-composer]',
    );

    const textarea = form.querySelector(
        '[data-conversation-question]',
    );

    const button = form.querySelector(
        '[data-conversation-submit]',
    );

    return {
        form,
        textarea,
        button,
    };
};

describe('conversation Markdown', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('renders diagrams for finished messages but defers them during streaming', () => {
        const element = document.createElement('div');
        const source = '```mermaid\ngraph TD\nA-->B\n```';
        renderConversationMarkdown(element, source, { diagrams: false });
        expect(diagrams.render).not.toHaveBeenCalled();
        renderConversationMarkdown(element, source);
        expect(diagrams.render).toHaveBeenCalledWith(element);
    });

    it('renders GFM Markdown and sanitizes untrusted HTML and links', () => {
        const element = document.createElement('div');

        renderConversationMarkdown(
            element,
            [
                '# عنوان',
                '',
                '**نص مهم**',
                '',
                '<script>alert("x")</script>',
                '',
                '[خطر](javascript:alert("x"))',
                '',
                '[آمن](https://example.com/path)',
                '',
                '| أ | ب |',
                '| - | - |',
                '| 1 | 2 |',
            ].join('\n'),
        );

        expect(
            element.querySelector('h1')?.textContent,
        ).toBe('عنوان');

        expect(
            element.querySelector('strong')?.textContent,
        ).toBe('نص مهم');

        expect(
            element.querySelector('script'),
        ).toBeNull();

        const anchors =
            element.querySelectorAll('a');

        expect(anchors).toHaveLength(2);

        expect(
            anchors[0].hasAttribute('href'),
        ).toBe(false);

        expect(
            anchors[1].getAttribute('href'),
        ).toBe('https://example.com/path');

        expect(
            anchors[1].getAttribute('target'),
        ).toBe('_blank');

        expect(
            anchors[1].getAttribute('rel'),
        ).toBe('noopener noreferrer');

        expect(
            element.querySelector('table'),
        ).not.toBeNull();
    });
});

describe('conversation answer streaming', () => {
    beforeEach(() => {
        document.body.innerHTML = '';

        FakeEventSource.instances = [];

        window.EventSource = FakeEventSource;
        globalThis.EventSource = FakeEventSource;

        const animationFrames = [];

        globalThis.requestAnimationFrame = vi.fn(
            (callback) => {
                animationFrames.push(callback);

                return animationFrames.length;
            },
        );

        globalThis.cancelAnimationFrame = vi.fn();

        globalThis.flushAnimationFrames = () => {
            while (animationFrames.length > 0) {
                const callback = animationFrames.shift();

                callback();
            }
        };

        window.Livewire = {
            dispatch: vi.fn(),
        };
    });

    it('concatenates provider chunks exactly before Markdown rendering', () => {
        const element =
            document.createElement('article');

        element.dataset.streamUrl =
            '/conversation-stream';

        element.dataset.streamAssistantId =
            'assistant-501';

        element.innerHTML = `
            <div
                data-stream-content
                aria-busy="true"
            ></div>
        `;

        document.body.appendChild(element);

        startConversationAnswerStream(
            element,
        );

        expect(
            FakeEventSource.instances,
        ).toHaveLength(1);

        const source =
            FakeEventSource.instances[0];

        source.emit(
            'token',
            JSON.stringify({
                content: '**Hel',
            }),
        );

        source.emit(
            'token',
            JSON.stringify({
                content: 'lo**',
            }),
        );

        globalThis.flushAnimationFrames();

        const content =
            element.querySelector(
                '[data-stream-content]',
            );

        expect(
            content.querySelector('strong')
                ?.textContent,
        ).toBe('Hello');

        source.emit(
            'completed',
        );

        expect(source.closed).toBe(true);

        expect(
            content.getAttribute(
                'aria-busy',
            ),
        ).toBe('false');

        expect(
            window.Livewire.dispatch,
        ).toHaveBeenCalledWith(
            'conversation-answer-terminal',
        );
    });
});

describe('conversation composer', () => {
    beforeEach(() => {
        document.body.innerHTML = '';

        globalThis.requestAnimationFrame = vi.fn(
            (callback) => {
                callback();

                return 1;
            },
        );
    });

    it('submits with Enter but keeps Shift+Enter for a newline', () => {
        const {
            form,
            textarea,
            button,
        } = composer();

        const clickSpy = vi
            .spyOn(button, 'click')
            .mockImplementation(() => {
                form.dispatchEvent(
                    new Event(
                        'submit',
                        {
                            bubbles: true,
                            cancelable: true,
                        },
                    ),
                );
            });

        initializeConversationComposer(
            form,
        );

        textarea.value = 'سؤال فعلي';

        const enter = new KeyboardEvent(
            'keydown',
            {
                key: 'Enter',
                bubbles: true,
                cancelable: true,
            },
        );

        textarea.dispatchEvent(enter);

        expect(
            enter.defaultPrevented,
        ).toBe(true);

        expect(
            clickSpy,
        ).toHaveBeenCalledTimes(1);

        const shiftEnter =
            new KeyboardEvent(
                'keydown',
                {
                    key: 'Enter',
                    shiftKey: true,
                    bubbles: true,
                    cancelable: true,
                },
            );

        textarea.dispatchEvent(
            shiftEnter,
        );

        expect(
            shiftEnter.defaultPrevented,
        ).toBe(false);

        expect(
            clickSpy,
        ).toHaveBeenCalledTimes(1);
    });

    it('blocks duplicate submit events while a submission is active', () => {
        const {
            form,
        } = composer();

        initializeConversationComposer(
            form,
        );

        const first =
            new SubmitEvent(
                'submit',
                {
                    bubbles: true,
                    cancelable: true,
                },
            );

        form.dispatchEvent(first);

        expect(
            first.defaultPrevented,
        ).toBe(false);

        expect(
            form.dataset.submitting,
        ).toBe('true');

        const duplicate =
            new SubmitEvent(
                'submit',
                {
                    bubbles: true,
                    cancelable: true,
                },
            );

        form.dispatchEvent(
            duplicate,
        );

        expect(
            duplicate.defaultPrevented,
        ).toBe(true);
    });
});


describe('Markdown after Livewire morphs', () => {
    afterAll(async () => {
        document.body.replaceChildren();
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    it('renders when a pending node becomes a completed Markdown node', async () => {
        initializeConversationUi();
        document.dispatchEvent(new Event('DOMContentLoaded'));
        document.body.innerHTML = '<div id="answer">Preparing</div>';
        await settle();
        const answer = document.querySelector('#answer');
        answer.textContent = '**1 مارس 1887**';
        answer.setAttribute('data-assistant-markdown', '');
        answer.setAttribute('data-markdown-source', '**1 مارس 1887**');
        await settle();
        expect(answer.querySelector('strong')?.textContent).toBe('1 مارس 1887');
    });

    it('restores formatting when Livewire overwrites an already-rendered node', async () => {
        document.body.innerHTML = '<div data-assistant-markdown data-markdown-source="**الجواب**">**الجواب**</div>';
        await settle();
        const answer = document.querySelector('[data-assistant-markdown]');
        expect(answer.querySelector('strong')?.textContent).toBe('الجواب');
        answer.textContent = '**الجواب**';
        await settle();
        expect(answer.querySelector('strong')?.textContent).toBe('الجواب');
        answer.dataset.markdownSource = '- عنصر جديد';
        answer.textContent = '- عنصر جديد';
        await settle();
        expect(answer.querySelector('li')?.textContent).toBe('عنصر جديد');
    });
});

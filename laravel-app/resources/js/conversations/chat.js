import DOMPurify from 'dompurify';
import { marked } from 'marked';

const conversationAnswerStreams = new Map();
const initializedComposers = new WeakSet();
const renderedHistoricalMarkdown = new WeakMap();

let conversationUiInitialized = false;
let markdownObserver = null;

marked.use({
    gfm: true,
    breaks: false,
});

const sanitizeMarkdownHtml = (html) => DOMPurify.sanitize(
    html,
    {
        USE_PROFILES: {
            html: true,
        },
        FORBID_TAGS: [
            'script',
            'style',
            'iframe',
            'object',
            'embed',
            'form',
            'input',
            'button',
            'textarea',
            'select',
            'option',
            'svg',
            'math',
        ],
        FORBID_ATTR: [
            'style',
        ],
        ALLOW_DATA_ATTR: false,
    },
);

const secureRenderedLinks = (element) => {
    element.querySelectorAll('a[href]').forEach((anchor) => {
        const href = anchor.getAttribute('href')?.trim();

        if (! href) {
            return;
        }

        let url;

        try {
            url = new URL(href, window.location.href);
        } catch {
            anchor.removeAttribute('href');

            return;
        }

        if (
            url.protocol !== 'http:'
            && url.protocol !== 'https:'
            && url.protocol !== 'mailto:'
        ) {
            anchor.removeAttribute('href');
            anchor.removeAttribute('target');
            anchor.removeAttribute('rel');

            return;
        }

        if (
            url.protocol === 'http:'
            || url.protocol === 'https:'
        ) {
            anchor.setAttribute('target', '_blank');
            anchor.setAttribute(
                'rel',
                'noopener noreferrer',
            );
        }
    });
};

export const renderConversationMarkdown = (
    element,
    markdown,
) => {
    if (! element || typeof markdown !== 'string') {
        return;
    }

    const rendered = marked.parse(markdown);

    element.innerHTML = sanitizeMarkdownHtml(
        typeof rendered === 'string'
            ? rendered
            : '',
    );

    secureRenderedLinks(element);
};

const renderHistoricalMarkdownElement = (
    element,
) => {
    if (! element) {
        return;
    }

    const previous = renderedHistoricalMarkdown.get(element);
    const source = element.dataset.markdownSource;

    if (
        previous?.html === element.innerHTML
        && (source === undefined || previous.source === source)
    ) {
        return;
    }

    const rawMarkdown = source ?? element.textContent ?? '';
    renderConversationMarkdown(element, rawMarkdown);
    renderedHistoricalMarkdown.set(element, {
        source: rawMarkdown,
        html: element.innerHTML,
    });
};

const renderHistoricalMarkdownWithin = (
    root,
) => {
    if (
        root instanceof Element
        && root.matches('[data-assistant-markdown]')
    ) {
        renderHistoricalMarkdownElement(root);
    }

    if (
        root instanceof Document
        || root instanceof DocumentFragment
        || root instanceof Element
    ) {
        root.querySelectorAll(
            '[data-assistant-markdown]',
        ).forEach(
            renderHistoricalMarkdownElement,
        );
    }
};

const startHistoricalMarkdownObserver = () => {
    if (
        markdownObserver
        || ! document.body
    ) {
        return;
    }

    renderHistoricalMarkdownWithin(document);

    markdownObserver = new MutationObserver(
        (records) => {
            records.forEach((record) => {
                const target = record.target instanceof Element
                    ? record.target
                    : record.target.parentElement;
                const answer = target?.closest('[data-assistant-markdown]');
                if (answer) {
                    renderHistoricalMarkdownElement(answer);
                }
                record.addedNodes.forEach((node) => {
                    if (
                        node instanceof Element
                        || node instanceof DocumentFragment
                    ) {
                        renderHistoricalMarkdownWithin(node);
                    }
                });
            });
        },
    );

    markdownObserver.observe(
        document.body,
        {
            childList: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['data-assistant-markdown', 'data-markdown-source'],
            subtree: true,
        },
    );
};

export const startConversationAnswerStream = (
    element,
) => {
    const url = element.dataset.streamUrl;
    const messageId =
        element.dataset.streamAssistantId;

    if (
        typeof url !== 'string'
        || url === ''
        || typeof messageId !== 'string'
        || messageId === ''
        || typeof window.EventSource !== 'function'
    ) {
        return;
    }

    const existing =
        conversationAnswerStreams.get(
            messageId,
        );

    if (existing?.element === element) {
        return;
    }

    if (existing) {
        existing.source.close();

        if (existing.renderFrame !== null) {
            cancelAnimationFrame(
                existing.renderFrame,
            );
        }

        conversationAnswerStreams.delete(
            messageId,
        );
    }

    const content =
        element.querySelector(
            '[data-stream-content]',
        );

    if (! content) {
        return;
    }

    const source = new EventSource(url);

    const state = {
        source,
        element,
        content,
        rawBuffer: '',
        receivedToken: false,
        renderFrame: null,
    };

    conversationAnswerStreams.set(
        messageId,
        state,
    );

    const renderBuffer = () => {
        state.renderFrame = null;

        renderConversationMarkdown(
            state.content,
            state.rawBuffer,
        );
    };

    const scheduleRender = () => {
        if (state.renderFrame !== null) {
            return;
        }

        state.renderFrame =
            requestAnimationFrame(
                renderBuffer,
            );
    };

    source.addEventListener(
        'token',
        (event) => {
            let payload;

            try {
                payload = JSON.parse(
                    event.data,
                );
            } catch {
                return;
            }

            if (
                typeof payload?.content
                !== 'string'
            ) {
                return;
            }

            state.receivedToken = true;
            state.rawBuffer += payload.content;

            scheduleRender();
        },
    );

    const terminal = () => {
        source.close();

        if (state.renderFrame !== null) {
            cancelAnimationFrame(
                state.renderFrame,
            );

            state.renderFrame = null;
        }

        if (state.receivedToken) {
            renderBuffer();
        }

        state.content.setAttribute(
            'aria-busy',
            'false',
        );

        conversationAnswerStreams.delete(
            messageId,
        );

        if (
            typeof window.Livewire?.dispatch
            === 'function'
        ) {
            window.Livewire.dispatch(
                'conversation-answer-terminal',
            );
        }
    };

    source.addEventListener(
        'completed',
        terminal,
    );

    source.addEventListener(
        'failed',
        terminal,
    );
};

const conversationTextarea = (form) =>
    form.querySelector(
        '[data-conversation-question]',
    );

const conversationSubmitButton = (form) =>
    form.querySelector(
        '[data-conversation-submit]',
    );

const resizeConversationTextarea = (
    form,
) => {
    const textarea =
        conversationTextarea(form);

    if (! textarea) {
        return;
    }

    const maxHeight = 192;

    textarea.style.height = '0px';

    const height = Math.min(
        textarea.scrollHeight,
        maxHeight,
    );

    textarea.style.height =
        `${height}px`;

    textarea.style.overflowY =
        textarea.scrollHeight > maxHeight
            ? 'auto'
            : 'hidden';
};

export const initializeConversationComposer = (
    form,
) => {
    if (
        ! form
        || initializedComposers.has(form)
    ) {
        return;
    }

    initializedComposers.add(form);

    let isSubmitting = false;
    let observedLoading = false;

    const resetSubmitting = () => {
        isSubmitting = false;
        observedLoading = false;

        delete form.dataset.submitting;

        requestAnimationFrame(() => {
            resizeConversationTextarea(
                form,
            );
        });
    };

    form.addEventListener(
        'input',
        (event) => {
            if (
                event.target.matches(
                    '[data-conversation-question]',
                )
            ) {
                resizeConversationTextarea(
                    form,
                );
            }
        },
    );

    form.addEventListener(
        'keydown',
        (event) => {
            if (
                ! event.target.matches(
                    '[data-conversation-question]',
                )
                || event.key !== 'Enter'
                || event.shiftKey
                || event.isComposing
                || event.repeat
            ) {
                return;
            }

            event.preventDefault();

            const textarea =
                conversationTextarea(form);

            const submitButton =
                conversationSubmitButton(form);

            if (
                ! textarea
                || textarea.value.trim() === ''
                || isSubmitting
                || submitButton?.disabled
            ) {
                return;
            }

            if (submitButton) {
                submitButton.click();

                return;
            }

            form.requestSubmit();
        },
    );

    form.addEventListener(
        'submit',
        (event) => {
            if (isSubmitting) {
                event.preventDefault();
                event.stopImmediatePropagation();

                return;
            }

            isSubmitting = true;
            form.dataset.submitting = 'true';
        },
        true,
    );

    const loadingObserver =
        new MutationObserver(() => {
            const submitButton =
                conversationSubmitButton(form);

            if (! submitButton) {
                return;
            }

            if (submitButton.disabled) {
                observedLoading = true;

                return;
            }

            if (observedLoading) {
                resetSubmitting();
            }
        });

    loadingObserver.observe(
        form,
        {
            attributes: true,
            attributeFilter: [
                'disabled',
            ],
            subtree: true,
        },
    );

    requestAnimationFrame(() => {
        resizeConversationTextarea(form);
    });
};

export const initializeConversationUi = () => {
    if (conversationUiInitialized) {
        return;
    }

    conversationUiInitialized = true;

    window.startConversationAnswerStream =
        startConversationAnswerStream;

    window.initializeConversationComposer =
        initializeConversationComposer;

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            startHistoricalMarkdownObserver,
            {
                once: true,
            },
        );
    } else {
        startHistoricalMarkdownObserver();
    }

    document.addEventListener(
        'livewire:navigated',
        () => {
            renderHistoricalMarkdownWithin(
                document,
            );
        },
    );
};

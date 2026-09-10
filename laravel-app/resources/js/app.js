import {
    initializeConversationUi,
} from './conversations/chat';

const DOCUMENT_STATE_SCROLL_KEY =
    'rag-document-state-scroll-y';

document.addEventListener(
    'rag-document-state-changed',
    (event) => {
        const url = event.detail?.url;

        if (
            typeof url !== 'string'
            || typeof window.Livewire?.navigate
                !== 'function'
        ) {
            return;
        }

        sessionStorage.setItem(
            DOCUMENT_STATE_SCROLL_KEY,
            String(window.scrollY),
        );

        window.Livewire.navigate(url);
    },
);

document.addEventListener(
    'livewire:navigated',
    () => {
        const storedScrollY =
            sessionStorage.getItem(
                DOCUMENT_STATE_SCROLL_KEY,
            );

        if (storedScrollY === null) {
            return;
        }

        sessionStorage.removeItem(
            DOCUMENT_STATE_SCROLL_KEY,
        );

        const scrollY =
            Number(storedScrollY);

        if (! Number.isFinite(scrollY)) {
            return;
        }

        requestAnimationFrame(() => {
            window.scrollTo({
                top: scrollY,
                left: 0,
                behavior: 'auto',
            });
        });
    },
);

initializeConversationUi();

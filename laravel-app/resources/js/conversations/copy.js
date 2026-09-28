let initialized = false;

const updateCopyButtonState = (button, message, copied = false) => {
    const icon = button.querySelector('[data-copy-icon]');
    const successIcon = button.querySelector('[data-copy-success-icon]');
    const feedback = button.querySelector('[data-copy-feedback]');

    if (!icon || !successIcon || !feedback) {
        button.textContent = message;
        return;
    }

    button.setAttribute('aria-label', message);
    button.setAttribute('title', message);
    icon.hidden = copied;
    successIcon.hidden = !copied;
    feedback.textContent = message;
};

export const copyConversationText = async (button) => {
    if (button.disabled) return;
    const text = button.matches('[data-copy-diagram]')
        ? button.closest('[data-conversation-diagram]')?.querySelector('code')?.textContent
        : button.closest('[data-conversation-message]')?.querySelector('[data-message-copy-source]')?.dataset.messageCopySource;
    if (typeof text !== 'string') return;

    // ننسخ النص الأصلي لا النص المعروض الذي قد يحتوي أزراراً أو تفاصيل مصادر.
    const label = button.dataset.copyLabel
        ?? button.getAttribute('aria-label')
        ?? button.textContent;
    button.dataset.copyLabel = label;
    button.disabled = true;
    try {
        await navigator.clipboard.writeText(text);
        updateCopyButtonState(button, 'تم النسخ', true);
    } catch {
        // قد يمنع المتصفح الحافظة أو يعمل الموقع عبر HTTP غير آمن؛ لا ندّعي النجاح.
        updateCopyButtonState(
            button,
            'تعذّر النسخ؛ حدّد النص وانسخه يدوياً',
        );
    } finally {
        button.disabled = false;
        clearTimeout(button.copyResetTimer);
        button.copyResetTimer = setTimeout(() => {
            updateCopyButtonState(button, label);
        }, 3000);
    }
};

export const initializeConversationCopy = () => {
    if (initialized) return;
    initialized = true;
    // التفويض يدعم الرسائل الجديدة والتنقل في Livewire دون تكرار المستمعين.
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-copy-message], [data-copy-diagram]')
            : null;
        if (button) void copyConversationText(button);
    });
};

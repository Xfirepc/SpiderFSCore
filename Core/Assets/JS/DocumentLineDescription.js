$(document).on(
    'input focusin',
    'form[name="salesForm"] textarea.doc-line-desc, form[name="purchasesForm"] textarea.doc-line-desc',
    function () {
        if (this.readOnly || this.disabled || this.clientHeight === 0) {
            return;
        }

        const field = $(this);
        const styles = window.getComputedStyle(this);
        const padding = parseFloat(styles.paddingTop) + parseFloat(styles.paddingBottom);
        const height = Math.min(200, Math.ceil(this.scrollHeight - padding));

        // Ajustamos una sola vez: una transición o un max-height no deben bloquear la página.
        if (height > field.height()) {
            field.height(height);
        }
    }
);

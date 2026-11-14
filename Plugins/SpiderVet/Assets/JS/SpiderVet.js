document.addEventListener('DOMContentLoaded', function () {
    var printButton = document.getElementById('sv-print-button');
    if (printButton) {
        printButton.addEventListener('click', function () { window.print(); });
    }
    document.querySelectorAll('.sv-check-in').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.textContent = 'Abriendo recepción…'; }
        });
    });
});

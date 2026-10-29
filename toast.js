document.querySelectorAll('.toast').forEach((toast) => {
    const dismiss = () => toast.classList.add('is-dismissed');

    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    window.setTimeout(dismiss, 6000);
});

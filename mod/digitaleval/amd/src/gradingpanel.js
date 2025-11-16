// mod/digitaleval/amd/src/gradingpanel.js
define([], function() {
    const SELECTORS = {
        button: '#start-grading-button'
    };

    const init = () => {
        const button = document.querySelector(SELECTORS.button);
        if (!button) {
            return;
        }
        button.addEventListener('click', function() {
            const cmid = button.getAttribute('data-cmid') || button.dataset.cmid;
            // Navigate in same tab to emulate mod_assign behavior (Back button works)
            window.location.href = M.cfg.wwwroot + '/mod/digitaleval/grading.php?id=' + encodeURIComponent(cmid);
        });
    };

    return { init: init };
});
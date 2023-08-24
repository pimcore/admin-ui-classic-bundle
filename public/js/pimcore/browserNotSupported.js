document.getElementById('proceed_with_unsupported_browser').addEventListener('click', showLogin);

/**
 * @private
 */
function showLogin() {
    document.getElementById('loginform').style.display = 'block';
    document.getElementById('browserinfo').style.display = 'none';
}
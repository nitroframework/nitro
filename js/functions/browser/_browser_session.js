// uses: _browser_storage
/** Nitro::browser()->session(): sessionStorage. */
function _browser_session(browser) {
    return P.fns._browser_storage(browser, true);
}

// Fusion: mbstring's character_width(), from its table (_mb_wide).
// uses: _mb_wide
function _mb_char_width(codePoint) {
    const ranges = P.fns._mb_wide();
    let low = 0;
    let high = ranges.length - 1;
    while (low <= high) {
        const mid = (low + high) >> 1;
        if (codePoint < ranges[mid][0]) high = mid - 1;
        else if (codePoint > ranges[mid][1]) low = mid + 1;
        else return 2;
    }
    return 1;
}

/** PHP 8's sorts: stable, with Zend's hybrid insertion sort, so ties and comparisons order as in PHP. Part of php.js. */
import { toInt } from './conversions.js';
import { cmp, sign } from './operators.js';
import { callable } from './functions.js';

/** Stable sort (PHP 8 sorts are stable). */
export function sortEntries(list, compare) {
    /** PHP 8's sorts are stable: a tie keeps the original order. */
    const items = list.map((x, i) => ({ x, i }));
    zendSort(items, 0, items.length, (a, b) => compare(a.x, b.x) || (a.i > b.i ? 1 : a.i < b.i ? -1 : 0));
    return items.map(({ x }) => x);
}

/**
 * A comparison callback as PHP calls it: its result as an int's sign; false is asked again with
 * the arguments swapped (PHP deprecates returning a bool).
 */
export function userCompare(cb, value) {
    const fn = callable(cb);
    return (a, b) => {
        const result = fn(value(a), value(b));
        if (result === false) return -Math.sign(toInt(fn(value(b), value(a))));
        return Math.sign(toInt(result));
    };
}

/**
 * zend_sort (Zend/zend_sort.c), step for step: insertion sort up to 16 items, quicksort above
 * (a median of 3 pivot, of 5 from 1024 items). The same comparisons in the same order, so a
 * comparison that is no ordering ($x + $y) gives what it gives in PHP.
 */
function zendSort(a, base, n, cmp) {
    while (true) {
        if (n <= 16) {
            insertSort(a, base, n, cmp);
            return;
        }
        const start = base;
        const end = start + n;
        const offset = n >> 1;
        let pivot = start + offset;
        if (n >> 10) {
            const delta = offset >> 1;
            sort5(a, start, start + delta, pivot, pivot + delta, end - 1, cmp);
        } else {
            sort3(a, start, pivot, end - 1, cmp);
        }
        swap(a, start + 1, pivot);
        pivot = start + 1;
        let i = pivot + 1;
        let j = end - 1;
        partition: while (true) {
            while (cmp(a[pivot], a[i]) > 0) {
                i++;
                if (i === j) break partition;
            }
            j--;
            if (j === i) break;
            while (cmp(a[j], a[pivot]) > 0) {
                j--;
                if (j === i) break partition;
            }
            swap(a, i, j);
            i++;
            if (i === j) break;
        }
        swap(a, pivot, i - 1);
        if (i - 1 - start < end - i) {
            zendSort(a, start, i - start - 1, cmp);
            base = i;
            n = end - i;
        } else {
            zendSort(a, i, end - i, cmp);
            n = i - start - 1;
        }
    }
}

function insertSort(a, start, n, cmp) {
    switch (n) {
        case 0: case 1: return;
        case 2: sort2(a, start, start + 1, cmp); return;
        case 3: sort3(a, start, start + 1, start + 2, cmp); return;
        case 4: sort4(a, start, start + 1, start + 2, start + 3, cmp); return;
        case 5: sort5(a, start, start + 1, start + 2, start + 3, start + 4, cmp); return;
    }
    const end = start + n;
    const sentry = start + 6;
    for (let i = start + 1; i < sentry; i++) {
        let j = i - 1;
        if (!(cmp(a[j], a[i]) > 0)) continue;
        while (j !== start) {
            j--;
            if (!(cmp(a[j], a[i]) > 0)) {
                j++;
                break;
            }
        }
        for (let k = i; k > j; k--) swap(a, k, k - 1);
    }
    for (let i = sentry; i < end; i++) {
        let j = i - 1;
        if (!(cmp(a[j], a[i]) > 0)) continue;
        while (true) {
            j -= 2;
            if (!(cmp(a[j], a[i]) > 0)) {
                j++;
                if (!(cmp(a[j], a[i]) > 0)) j++;
                break;
            }
            if (j === start) break;
            if (j === start + 1) {
                j--;
                if (cmp(a[i], a[j]) > 0) j++;
                break;
            }
        }
        for (let k = i; k > j; k--) swap(a, k, k - 1);
    }
}

function swap(a, i, j) {
    const t = a[i];
    a[i] = a[j];
    a[j] = t;
}

function sort2(a, i, j, cmp) {
    if (cmp(a[i], a[j]) > 0) swap(a, i, j);
}

function sort3(a, i, j, k, cmp) {
    if (!(cmp(a[i], a[j]) > 0)) {
        if (!(cmp(a[j], a[k]) > 0)) return;
        swap(a, j, k);
        if (cmp(a[i], a[j]) > 0) swap(a, i, j);
        return;
    }
    if (!(cmp(a[k], a[j]) > 0)) {
        swap(a, i, k);
        return;
    }
    swap(a, i, j);
    if (cmp(a[j], a[k]) > 0) swap(a, j, k);
}

function sort4(a, i, j, k, l, cmp) {
    sort3(a, i, j, k, cmp);
    if (cmp(a[k], a[l]) > 0) {
        swap(a, k, l);
        if (cmp(a[j], a[k]) > 0) {
            swap(a, j, k);
            if (cmp(a[i], a[j]) > 0) swap(a, i, j);
        }
    }
}

function sort5(a, i, j, k, l, m, cmp) {
    sort4(a, i, j, k, l, cmp);
    if (cmp(a[l], a[m]) > 0) {
        swap(a, l, m);
        if (cmp(a[k], a[l]) > 0) {
            swap(a, k, l);
            if (cmp(a[j], a[k]) > 0) {
                swap(a, j, k);
                if (cmp(a[i], a[j]) > 0) swap(a, i, j);
            }
        }
    }
}

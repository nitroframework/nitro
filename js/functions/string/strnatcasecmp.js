// Viewi's PhpJsFunctions/Strings/Strnatcasecmp.js, for Fusion's values.
// uses: strnatcmp
function strnatcasecmp (a, b) {
  //       discuss at: https://locutus.io/php/strnatcasecmp/
  //      original by: Martin Pool
  // reimplemented by: Pierre-Luc Paour
  // reimplemented by: Kristof Coomans (SCK-CEN (Belgian Nucleair Research Centre))
  // reimplemented by: Brett Zamir (https://brett-zamir.me)
  //      bugfixed by: Kevin van Zonneveld (https://kvz.io)
  //         input by: Devan Penner-Woelk
  //      improved by: Kevin van Zonneveld (https://kvz.io)
  // reimplemented by: Rafał Kukawski
  //        example 1: strnatcasecmp(10, 1)
  //        returns 1: 1
  //        example 2: strnatcasecmp('1', '10')
  //        returns 2: -1




  if (arguments.length !== 2) {
    return null
  }

  return P.fns.strnatcmp(P.toStr(a).toLowerCase(), P.toStr(b).toLowerCase())
}

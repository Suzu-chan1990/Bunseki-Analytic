/**
 * Bunseki Analytic – Frontend Tracker
 *
 * Fixes gegenüber v1.1.2:
 *  - endpoint-Variable war im bunseki.track()-Closure undefined (Scope-Bug).
 *    Jetzt wird window.bunsekiAjax.rest_url direkt beim Aufruf gelesen.
 *  - Heartbeat-Interval von 10s auf 30s erhöht (weniger DB-Last).
 *  - DoNotTrack-Check am Anfang zusammengefasst (DRY).
 *  - sendBeacon-Fallback sauber mit XHR implementiert.
 *
 * @since 1.2.0
 */

window.addEventListener( 'load', function () {

    // ----------------------------------------------------------------
    // 0. DNT & Opt-Out-Cookie: sofort beenden wenn aktiv
    // ----------------------------------------------------------------
    if (
        navigator.doNotTrack === '1' ||
        window.doNotTrack    === '1' ||
        document.cookie.indexOf( 'bunseki_dnt=1' ) !== -1
    ) return;

    // ----------------------------------------------------------------
    // 1. Endpunkt aus wp_localize_script-Objekt lesen
    //    FIX: Wird jetzt erst beim tatsächlichen Aufruf von send()
    //    ausgelesen, nicht bei Funktionsdefinition – kein Scope-Problem.
    // ----------------------------------------------------------------
    function getEndpoint() {
        return ( window.bunsekiAjax && window.bunsekiAjax.rest_url )
            ? window.bunsekiAjax.rest_url
            : '';
    }

    // ----------------------------------------------------------------
    // 2. Aktive Zeit messen (nur echte Nutzeraktivität)
    // ----------------------------------------------------------------
    var active_time   = 0;
    var last_time     = Date.now();
    var last_activity = Date.now();

    [ 'mousemove', 'keydown', 'scroll', 'click', 'touchstart' ].forEach( function ( e ) {
        window.addEventListener( e, function () {
            last_activity = Date.now();
        }, { passive: true } );
    } );

    setInterval( function () {
        var now = Date.now();
        // Nur zählen wenn innerhalb der letzten 30s Aktivität war
        if ( now - last_activity < 30000 ) {
            active_time += ( now - last_time );
        }
        last_time = now;
    }, 1000 );

    // ----------------------------------------------------------------
    // 3. Performance-Metriken (TTFB & Load-Time)
    // ----------------------------------------------------------------
    var ttfb = 0;
    var load = 0;
    var perf = window.performance || {};
    if ( perf.timing ) {
        ttfb = perf.timing.responseStart           - perf.timing.navigationStart;
        load = perf.timing.domContentLoadedEventEnd - perf.timing.navigationStart;
    }

    // ----------------------------------------------------------------
    // 4. URL-Parameter (UTM & Suche)
    // ----------------------------------------------------------------
    var params       = new URLSearchParams( window.location.search );
    var search_term  = params.get( 's' ) || '';
    var search_count = 1;
    if (
        search_term &&
        ( document.body.classList.contains( 'no-results' ) ||
          document.querySelector( '.no-results' ) )
    ) {
        search_count = 0;
    }

    // ----------------------------------------------------------------
    // 5. Initiale Nutzlast zusammenstellen
    // ----------------------------------------------------------------
    var config = window.bunseki_config || {};

    var data = {
        url      : window.location.pathname,
        referrer : document.referrer,
        width    : window.innerWidth,
        lang     : ( navigator.language || 'ja' ).slice( 0, 2 ).toLowerCase(),
        ttfb     : ttfb,
        load     : load,
        status   : config.status || 200,
        utm      : params.get( 'utm_source' ) || '',
        search   : search_term,
        found    : search_count,
        duration : 0,
        is_update: 0,
    };

    // ----------------------------------------------------------------
    // 6. Sende-Funktion (sendBeacon mit XHR-Fallback)
    // ----------------------------------------------------------------
    function send( is_final ) {
        var endpoint = getEndpoint();
        if ( ! endpoint ) return;

        if ( is_final ) {
            data.duration  = Math.round( active_time / 1000 );
            data.is_update = 1;
        } else {
            data.is_update = 0;
        }

        var fd = new FormData();
        Object.keys( data ).forEach( function ( key ) {
            fd.append( key, data[ key ] );
        } );

        if ( navigator.sendBeacon ) {
            navigator.sendBeacon( endpoint, fd );
        } else {
            // Fallback für ältere Browser
            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', endpoint, true );
            var parts = [];
            Object.keys( data ).forEach( function ( key ) {
                parts.push(
                    encodeURIComponent( key ) + '=' + encodeURIComponent( data[ key ] )
                );
            } );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
            xhr.send( parts.join( '&' ) );
        }
    }

    // ----------------------------------------------------------------
    // 7. Public Event Tracker API: bunseki.track('Name', 'Wert')
    //    FIX: endpoint wird jetzt via getEndpoint() zur Laufzeit gelesen.
    // ----------------------------------------------------------------
    window.bunseki          = window.bunseki || {};
    window.bunseki.track    = function ( name, value ) {
        if (
            navigator.doNotTrack === '1' ||
            window.doNotTrack    === '1' ||
            document.cookie.indexOf( 'bunseki_dnt=1' ) !== -1
        ) return;

        var endpoint = getEndpoint();
        if ( ! endpoint ) return;

        var fd = new FormData();
        fd.append( 'event_name', name );
        fd.append( 'event_val',  value || '' );
        fd.append( 'url',        window.location.pathname );

        if ( navigator.sendBeacon ) {
            navigator.sendBeacon( endpoint, fd );
        }
    };

    // ----------------------------------------------------------------
    // 8. Auto-Track: Outbound Links & Downloads
    // ----------------------------------------------------------------
    document.addEventListener( 'click', function ( e ) {
        var el = e.target.closest( 'a' );
        if ( ! el || ! el.href ) return;

        var href = el.href;
        var ext  = href.split( '?' )[ 0 ].split( '.' ).pop().toLowerCase();

        if ( [ 'pdf', 'zip', 'rar', 'doc', 'docx', 'xls', 'xlsx' ].indexOf( ext ) !== -1 ) {
            window.bunseki.track( 'Download', href );
        } else if ( el.host !== window.location.host && href.indexOf( 'http' ) === 0 ) {
            window.bunseki.track( 'Outbound Link', href );
        }
    } );

    // ----------------------------------------------------------------
    // 9. Tracking-Aufrufe
    //    FIX: Heartbeat von 10s auf 30s erhöht -> deutlich weniger
    //    DB-UPDATE-Queries bei vielen gleichzeitigen Besuchern.
    // ----------------------------------------------------------------

    // a) Initialer Seitenaufruf
    send( false );

    // b) Heartbeat alle 30 Sekunden (Verweildauer aktualisieren)
    setInterval( function () {
        send( true );
    }, 30000 );

    // c) Finaler Ping beim Verlassen der Seite
    document.addEventListener( 'visibilitychange', function () {
        if ( document.visibilityState === 'hidden' ) send( true );
    } );
    window.addEventListener( 'pagehide', function () {
        send( true );
    } );

} );

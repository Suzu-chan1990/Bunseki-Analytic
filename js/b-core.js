/**
 * Bunseki Analytic – Frontend Tracker
 *
 * Sends one beacon per pageview, a heartbeat every 30 s and a final ping on
 * page hide. Respects Do Not Track and the bunseki_dnt opt-out cookie.
 * Public API: bunseki.track( name, value ) for custom events.
 *
 * @since 1.2.0
 */

( function () {

    // ----------------------------------------------------------------
    // 0. DNT & opt-out cookie: stop immediately if set
    // ----------------------------------------------------------------
    if (
        navigator.doNotTrack === '1' ||
        window.doNotTrack    === '1' ||
        document.cookie.indexOf( 'bunseki_dnt=1' ) !== -1
    ) return;

    // ----------------------------------------------------------------
    // 1. Endpoint from the wp_localize_script object
    //    (read at send time, not at definition time)
    // ----------------------------------------------------------------
    function getEndpoint() {
        return ( window.bunsekiAjax && window.bunsekiAjax.rest_url )
            ? window.bunsekiAjax.rest_url
            : '';
    }

    // ----------------------------------------------------------------
    // 2. Measure active time (real user activity only)
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
        // Only count time if there was activity within the last 30 s
        if ( now - last_activity < 30000 ) {
            active_time += ( now - last_time );
        }
        last_time = now;
    }, 1000 );

    // ----------------------------------------------------------------
    // 3. Performance metrics (TTFB & load time)
    // ----------------------------------------------------------------
    var ttfb = 0;
    var load = 0;
    var perf = window.performance || {};

    // Re-read on every send: DOMContentLoaded may not be finished at the first beacon.
    function readPerf() {
        var nav = ( perf.getEntriesByType && perf.getEntriesByType( 'navigation' )[ 0 ] ) || null;
        if ( nav ) {
            ttfb = Math.max( 0, Math.round( nav.responseStart ) );
            load = nav.domContentLoadedEventEnd > 0 ? Math.round( nav.domContentLoadedEventEnd ) : 0;
        } else if ( perf.timing ) {
            ttfb = Math.max( 0, perf.timing.responseStart - perf.timing.navigationStart );
            load = perf.timing.domContentLoadedEventEnd > 0
                ? perf.timing.domContentLoadedEventEnd - perf.timing.navigationStart : 0;
        }
    }
    readPerf();

    // ----------------------------------------------------------------
    // 4. URL parameters (UTM & search)
    // ----------------------------------------------------------------
    var params       = new URLSearchParams( window.location.search );
    var search_term  = params.get( 's' ) || '';
    var search_count = 1;
    if (
        search_term &&
        ( ( document.body && document.body.classList.contains( 'no-results' ) ) ||
          document.querySelector( '.no-results' ) )
    ) {
        search_count = 0;
    }

    // ----------------------------------------------------------------
    // 5. Build the initial payload
    // ----------------------------------------------------------------
    var config = window.bunseki_config || {};

    var data = {
        url      : window.location.pathname,
        referrer : document.referrer,
        width    : window.innerWidth,
        touch    : navigator.maxTouchPoints || 0,
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
    // 6. Send function (sendBeacon with XHR fallback)
    // ----------------------------------------------------------------
    function send( is_final ) {
        var endpoint = getEndpoint();
        if ( ! endpoint ) return;

        readPerf();
        data.ttfb = ttfb;
        data.load = load;

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
            // Fallback for older browsers
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
    // 7. Public event tracker API: bunseki.track('Name', 'Value')
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
    // 8. Auto-track: outbound links & downloads
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
    // 9. Tracking calls (30 s heartbeat keeps DB updates low under load)
    // ----------------------------------------------------------------

    // a) Initial pageview
    send( false );

    // b) Heartbeat every 30 seconds (updates time on page)
    setInterval( function () {
        send( true );
    }, 30000 );

    // c) Final ping when the page is left
    document.addEventListener( 'visibilitychange', function () {
        if ( document.visibilityState === 'hidden' ) send( true );
    } );
    window.addEventListener( 'pagehide', function () {
        send( true );
    } );

    // Page restored from the back/forward cache: count it as a new view.
    window.addEventListener( 'pageshow', function ( e ) {
        if ( e.persisted ) {
            active_time   = 0;
            last_time     = Date.now();
            last_activity = Date.now();
            data.duration = 0;
            send( false );
        }
    } );

} )();

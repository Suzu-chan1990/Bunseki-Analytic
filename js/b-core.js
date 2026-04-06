
window.addEventListener('load', function() {
    if (navigator.doNotTrack === "1" || window.doNotTrack === "1" || document.cookie.indexOf("bunseki_dnt=1") !== -1) return;
    
    var start = new Date().getTime();
    var active_time = 0;
    var last_time = start;
    var last_activity = start;
    
    // Aktivitäts-Tracker
    ['mousemove','keydown','scroll','click','touchstart'].forEach(function(e){
        window.addEventListener(e, function(){ last_activity = new Date().getTime(); }, {passive:true});
    });
    
    // Zählt nur die wirklich aktiven Sekunden
    setInterval(function() {
        var now = new Date().getTime();
        if (now - last_activity < 30000) active_time += (now - last_time);
        last_time = now;
    }, 1000);
    
    var config = window.bunseki_config || {};
    
    // Performance Metrics
    var perf = window.performance || {};
    var ttfb = 0, load = 0;
    if (perf.timing) {
        ttfb = perf.timing.responseStart - perf.timing.navigationStart;
        load = perf.timing.domContentLoadedEventEnd - perf.timing.navigationStart;
    }

    // Event Tracker API
    window.bunseki = window.bunseki || {};
    window.bunseki.track = function(name, value) {
        if (navigator.doNotTrack === "1" || window.doNotTrack === "1" || document.cookie.indexOf("bunseki_dnt=1") !== -1) return;
        var fd = new FormData();
        fd.append('event_name', name);
        fd.append('event_val', value || '');
        fd.append('url', window.location.pathname);
        if(navigator.sendBeacon) navigator.sendBeacon(endpoint, fd);
    };

    // Auto-Track Outbound & Downloads
    document.addEventListener('click', function(e) {
        var el = e.target.closest('a');
        if(!el || !el.href) return;
        var href = el.href;
        var ext = href.split('?')[0].split('.').pop().toLowerCase();
        if(['pdf','zip','rar','doc','docx','xls','xlsx'].indexOf(ext) !== -1) {
            window.bunseki.track('Download', href);
        } else if (el.host !== window.location.host && href.indexOf('http') === 0) {
            window.bunseki.track('Outbound Link', href);
        }
    });

    // URL Params parsing (Search & UTM)
    var params = new URLSearchParams(window.location.search);
    var search_term = params.get('s') || '';
    
    // Optional: Versuche herauszufinden, ob es eine 0-Result-Search war
    // Wir schauen, ob im Body eine Klasse wie 'no-results' existiert (WordPress Standard)
    var search_count = 1; 
    if (search_term && (document.body.classList.contains('no-results') || document.querySelector('.no-results'))) {
        search_count = 0;
    }

    // Initial Data Collection
    var data = {
        url: window.location.pathname,
        referrer: document.referrer,
        width: window.innerWidth,
        lang: navigator.language,
        ttfb: ttfb,
        load: load,
        status: config.status || 200,
        utm: params.get('utm_source') || '',
        search: search_term,
        found: search_count,
        duration: 0 // Initial 0
    };

    var endpoint = window.bunsekiAjax ? window.bunsekiAjax.rest_url : '';
    if(!endpoint) return;

    // Helper to send data
    function send(is_final) {
        if (is_final) {
            data.duration = Math.round(active_time / 1000);
            data.is_update = 1;
        } else {
            data.is_update = 0;
        }

        if (navigator.sendBeacon) {
            var formData = new FormData();
            for (var key in data) formData.append(key, data[key]);
            // Bei Final Call: nutze sendBeacon (verhindert Abbruch beim Tab-Schließen)
            // Bei Initial Call: auch Beacon, ist am effizientesten
            navigator.sendBeacon(endpoint, formData);
        } else {
            // Fallback für uralte Browser
            var xhr = new XMLHttpRequest();
            xhr.open('POST', endpoint, true); // Async
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            var arr = [];
            for (var key in data) arr.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
            xhr.send(arr.join('&'));
        }
    }

    // 1. Initialer Seitenaufruf (erfasst den View garantiert)
    send(false);

    // 2. Heartbeat alle 10 Sekunden (aktualisiert die Verweildauer zuverlässig)
    setInterval(function() { send(true); }, 10000);

    // 3. Finaler Exit-Ping (für den exakten Exit-Moment)
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') send(true);
    });
    window.addEventListener('pagehide', function() { send(true); });
});

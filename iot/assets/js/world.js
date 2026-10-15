/*
 * The lake
 * --------
 * Renders the landscape every page lives in, drawn with WebGL behind the UI.
 * How it is painted comes from a style file loaded just before this one
 * (world-style-*.js); this file holds what every style shares: the camera,
 * the waterline, the lanterns and the drawing loop.
 *
 * The scene is tied to the layout:
 *   - the lake's waterline stays level with the navigation bar (.horizon),
 *     so the menu sits on the water and scrolls with it;
 *   - the hills lower themselves to leave room for the page title in the sky;
 *   - every page has a place along the shore (<body data-place>), and the
 *     camera pans a short way there from the previous page, near hills
 *     moving faster than far ones.
 *
 * Pages may float lanterns on the lake with ARWorld.setLanterns(total, lit).
 * Styles: ?world_style=stem (default), anime or vangogh; the choice is remembered.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var MOTION_KEY = 'ar-world-motion';
    var PAN_KEY = 'ar-world-pan';
    var MAX_LANTERNS = 16;

    var motionQuery = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function mix(a, b, t) {
        return a + (b - a) * t;
    }

    function easeInOutCubic(t) {
        return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }

    function hexToRgb(hex) {
        var value = parseInt(hex.slice(1), 16);

        return [
            ((value >> 16) & 255) / 255,
            ((value >> 8) & 255) / 255,
            (value & 255) / 255
        ];
    }

    function readStorage(storage, key) {
        try {
            return window[storage].getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeStorage(storage, key, value) {
        try {
            window[storage].setItem(key, value);
        } catch (error) {
            // Storage can be unavailable (private mode); the scene still works per page.
        }
    }

    var STYLE_KEY = 'ar-world-style';
    // "stem" is drawn by stem-world.js (three.js); the lake styles are drawn here.
    var DEFAULT_STYLE = 'stem';

    // Share of the screen width the near shore slides per place along the shore.
    var PAN_STEP = 0.2;

    function isKnownStyle(name) {
        return name === 'stem' || Boolean((window.ARWorldStyles || {})[name]);
    }

    function chooseStyle() {
        var match = /[?&]world_style=([a-z]+)/.exec(window.location.search);

        if (match && isKnownStyle(match[1])) {
            writeStorage('localStorage', STYLE_KEY, match[1]);
            return match[1];
        }

        var stored = readStorage('localStorage', STYLE_KEY);

        return stored && isKnownStyle(stored) ? stored : DEFAULT_STYLE;
    }

    var styleName = chooseStyle();
    var style = (window.ARWorldStyles || {})[styleName] || null;

    // The page's own colours follow the style (see world.css).
    root.dataset.worldStyle = styleName;

    // Content waits for motion.js to stage its entrance; never longer than this.
    if (!(motionQuery && motionQuery.matches)) {
        root.classList.add('motion-boot');
        window.setTimeout(function () {
            root.classList.remove('motion-boot');
        }, 2500);
    }

    var VERTEX_SHADER = [
        'attribute vec2 aPosition;',
        'void main() { gl_Position = vec4(aPosition, 0.0, 1.0); }'
    ].join('\n');

    // Shared by every style: uniforms, noise, birds and lanterns.
    var COMMON_SHADER = [
        '#ifdef GL_FRAGMENT_PRECISION_HIGH',
        'precision highp float;',
        '#else',
        'precision mediump float;',
        '#endif',
        'uniform vec2 uRes;',
        'uniform float uTime;',
        'uniform vec2 uSun;',
        'uniform float uMouse;',
        'uniform float uPan;',
        'uniform float uWater;',
        'uniform float uRelief;',
        'uniform vec4 uLantern[MAX_LANTERNS];',
        'uniform float uLanternLit[MAX_LANTERNS];',
        'uniform float uLanternCount;',
        '',
        'float hash(vec2 p) {',
        '    p = fract(p * vec2(123.34, 456.21));',
        '    p += dot(p, p + 45.32);',
        '    return fract(p.x * p.y);',
        '}',
        'float noise(vec2 p) {',
        '    vec2 i = floor(p);',
        '    vec2 f = fract(p);',
        '    vec2 u = f * f * (3.0 - 2.0 * f);',
        '    return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),',
        '               mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);',
        '}',
        'float fbm(vec2 p) {',
        '    float v = 0.0;',
        '    float a = 0.5;',
        '    mat2 m = mat2(1.6, 1.2, -1.2, 1.6);',
        '    for (int i = 0; i < 5; i++) { v += a * noise(p); p = m * p; a *= 0.5; }',
        '    return v;',
        '}',
        'float ridge(float x, float seed, float sharp) {',
        '    float v = 0.0;',
        '    float a = 0.5;',
        '    float f = 1.0;',
        '    for (int i = 0; i < 6; i++) {',
        '        float n = noise(vec2(x * f + seed, seed * 1.7));',
        '        n = mix(n, 1.0 - abs(n * 2.0 - 1.0), sharp);',
        '        v += a * n;',
        '        f *= 2.05;',
        '        a *= 0.47;',
        '    }',
        '    return v;',
        '}',
        'float segment(vec2 p, vec2 a, vec2 b) {',
        '    vec2 pa = p - a;',
        '    vec2 ba = b - a;',
        '    float h = clamp(dot(pa, ba) / dot(ba, ba), 0.0, 1.0);',
        '    return length(pa - ba * h);',
        '}',
        '',
        '/*',
        ' * Camera position along the shore for a layer at the given nearness. One',
        ' * place along the shore is a short step, so changing pages is a gentle move.',
        ' */',
        'float shoreX(float x, float aspect, float nearness) {',
        '    return x + (uPan * aspect * PAN_STEP + uMouse * 0.1) * nearness;',
        '}',
        '',
        'float sunSide() {',
        '    return uSun.x > 0.5 ? 1.0 : -1.0;',
        '}',
        '',
        '/* A few birds crossing the sky; returns how much ink covers p. */',
        'float birds(vec2 p, float aspect) {',
        '    float ink = 0.0;',
        '    for (int i = 0; i < 5; i++) {',
        '        float fi = float(i);',
        '        float speed = 0.012 + 0.002 * fi;',
        '        float span = aspect + 0.6;',
        '        float bx = fract(uTime * speed * 0.6 + 0.37) * span - span * 0.5 + sin(fi * 2.4) * 0.07;',
        '        float by = uWater + 0.36 + sin(fi * 1.7) * 0.035 + sin(uTime * 0.35 + fi) * 0.012;',
        '        vec2 bp = (vec2((p.x - 0.5) * aspect, p.y) - vec2(bx, by)) / (0.016 - fi * 0.0012);',
        '        bp.x = abs(bp.x);',
        '        float flap = sin(uTime * (7.0 + fi) + fi * 1.3);',
        '        vec2 elbow = vec2(0.5, 0.18 + 0.32 * flap);',
        '        vec2 tip = vec2(1.0, 0.05 + 0.45 * flap);',
        '        float dist = min(segment(bp, vec2(0.0), elbow), segment(bp, elbow, tip));',
        '        ink = max(ink, smoothstep(0.09, 0.05, dist));',
        '    }',
        '    return ink;',
        '}',
        '',
        '/*',
        ' * Paper lanterns on lotus floats. Waiting ones are plain white paper; once',
        ' * lit they turn red-orange, glow, and send a ring across the water.',
        ' */',
        'vec3 lanterns(vec3 col, vec2 uv, float aspect, float horizon, float ripple) {',
        '    vec2 pt = vec2((uv.x - 0.5) * aspect, uv.y);',
        '    for (int i = 0; i < MAX_LANTERNS; i++) {',
        '        if (float(i) >= uLanternCount) { break; }',
        '        vec4 lantern = uLantern[i];',
        '        float size = lantern.z;',
        '        float w = lantern.w;',
        '        vec2 lp = vec2((lantern.x - 0.5) * aspect, horizon - lantern.y);',
        '        vec2 d = pt - lp;',
        '',
        '        /* Ring spreading from a lantern that has just been lit. */',
        '        float age = uLanternLit[i];',
        '        if (age < 3.0) {',
        '            float radius = size * 1.2 + age * 0.05;',
        '            float r = length(d * vec2(1.0, 3.2));',
        '            float ring = smoothstep(size * 0.35, 0.0, abs(r - radius)) * pow(1.0 - age / 3.0, 2.0);',
        '            col = mix(col, vec3(1.0), ring * 0.7);',
        '        }',
        '',
        '        float shadowSpot = smoothstep(size * 1.4, size * 0.4, length(d * vec2(0.7, 2.4) + vec2(0.0, size * 0.25)));',
        '        col *= 1.0 - shadowSpot * 0.25;',
        '',
        '        float streak = exp(-abs(d.x + ripple * size * 1.6) / (size * 0.5)) * exp(d.y / (size * 6.0)) * step(d.y, 0.0);',
        '        col += mix(vec3(0.9, 0.95, 1.0) * 0.12, vec3(1.0, 0.45, 0.18) * 0.9, w) * streak;',
        '',
        '        float glow = exp(-length(d * vec2(1.0, 1.3)) / (size * 2.0));',
        '        col += vec3(1.0, 0.55, 0.25) * glow * 0.45 * w * w;',
        '',
        '        float base = smoothstep(size * 0.95, size * 0.8, length(d * vec2(0.72, 2.6) + vec2(0.0, size * 0.12)));',
        '        col = mix(col, vec3(0.13, 0.42, 0.3), base);',
        '',
        '        float body = smoothstep(size * 0.5, size * 0.44, abs(d.x))',
        '                   * smoothstep(-size * 0.06, 0.02 * size, d.y) * smoothstep(size * 1.2, size * 1.12, d.y);',
        '        float shade = clamp(d.y / (size * 1.2), 0.0, 1.0);',
        '        vec3 paper = mix(vec3(0.95, 0.93, 0.88), vec3(1.0, 0.42, 0.2), w);',
        '        vec3 inside = mix(paper * (0.82 + 0.18 * shade), vec3(1.0, 0.86, 0.55), w * (1.0 - shade) * 0.6);',
        '        col = mix(col, inside, body);',
        '    }',
        '    return col;',
        '}'
    ].join('\n');

    function compile(gl, type, source) {
        var shader = gl.createShader(type);

        gl.shaderSource(shader, source);
        gl.compileShader(shader);

        if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
            if (window.console) {
                console.warn('World background shader failed:', gl.getShaderInfoLog(shader));
            }
            gl.deleteShader(shader);
            return null;
        }

        return shader;
    }

    function createProgram(gl) {
        var vertex = compile(gl, gl.VERTEX_SHADER, VERTEX_SHADER);
        var fragment = compile(
            gl,
            gl.FRAGMENT_SHADER,
            '#define MAX_LANTERNS ' + MAX_LANTERNS + '\n'
                + '#define PAN_STEP ' + PAN_STEP.toFixed(3) + '\n'
                + COMMON_SHADER + '\n'
                + style.fragment
        );

        if (!vertex || !fragment) {
            return null;
        }

        var program = gl.createProgram();

        gl.attachShader(program, vertex);
        gl.attachShader(program, fragment);
        gl.linkProgram(program);

        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            return null;
        }

        return program;
    }

    /* ---------------------------------------------------------------
     * Layout links: waterline, room for the title, place on the shore
     * ------------------------------------------------------------- */
    var LIFTED_HEADER = [
        '.main-content > .page-header',
        '.main-content > .management-page-header',
        '.main-content > .results-detail-header',
        '.main-content > .question-result-header',
        '.main-content > .form-card:first-child > h1:first-child'
    ].join(',');

    var body = null;
    var nav = null;
    var skyHeader = null;
    var anchor = null;
    var lanternFloor = null;
    var waterlineOffset = 42;

    /*
     * Pages without the menu mark the waterline themselves:
     *   [data-waterline-anchor]  the lake starts at this element's top edge;
     *   [data-sky-content]       the lowest thing written on the sky;
     *   [data-lantern-floor]     lanterns stay above this element.
     */
    function readLayout() {
        body = document.body;
        nav = document.querySelector('.horizon');
        anchor = document.querySelector('[data-waterline-anchor]');
        lanternFloor = document.querySelector('[data-lantern-floor]');
        skyHeader = body.classList.contains('has-horizon')
            ? document.querySelector(LIFTED_HEADER)
            : document.querySelector('[data-sky-content]');

        if (nav) {
            waterlineOffset = parseFloat(
                window.getComputedStyle(nav).getPropertyValue('--waterline')
            ) || 42;
        }
    }

    /* Height of the waterline as a fraction of the viewport, from the bottom. */
    function waterLevel() {
        var height = window.innerHeight || 1;

        if (nav) {
            var top = nav.getBoundingClientRect().top + waterlineOffset;

            return clamp(1 - top / height, -0.2, 0.995);
        }

        if (anchor) {
            return clamp(1 - anchor.getBoundingClientRect().top / height, -0.2, 0.995);
        }

        var preset = parseFloat(body && body.dataset.horizon);

        return 1 - (isNaN(preset) ? 0.62 : preset);
    }

    /*
     * Make the sky band tall enough for the page title, then lower the hills
     * so their peaks stay below it.
     */
    function fitSky() {
        if (!skyHeader || !(nav || anchor)) {
            return 1;
        }

        if (!nav) {
            var room = anchor.getBoundingClientRect().top
                - skyHeader.getBoundingClientRect().bottom - 18;

            return clamp(room / (0.33 * (window.innerHeight || 1)), 0.2, 1);
        }

        var band = document.getElementById('sky-band');
        var height = window.innerHeight || 1;

        /*
         * The lifted title is placed from the top of the band, so growing
         * --sky-h (not the band alone) adds room between title and horizon.
         */
        body.style.removeProperty('--sky-h');

        if (band) {
            // Styles without hills may ask for less room under the title (--sky-min-gap).
            var minGap = parseFloat(window.getComputedStyle(body).getPropertyValue('--sky-min-gap'));
            var needed = skyHeader.getBoundingClientRect().bottom
                - band.getBoundingClientRect().top
                + (isNaN(minGap) ? Math.max(96, height * 0.13) : minGap);

            if (needed > band.offsetHeight) {
                body.style.setProperty('--sky-h', Math.ceil(needed) + 'px');
            }
        }

        var space = nav.getBoundingClientRect().top + waterlineOffset
            - skyHeader.getBoundingClientRect().bottom - 18;

        return clamp(space / (0.33 * height), 0.2, 1);
    }

    function start() {
        readLayout();

        // Another renderer draws this style; only keep the sky band sized to the title.
        if (!style) {
            fitSky();
            window.addEventListener('resize', fitSky);

            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(fitSky);
            }

            return;
        }

        var layer = document.createElement('div');
        var canvas = document.createElement('canvas');

        layer.className = 'world-layer';
        layer.setAttribute('aria-hidden', 'true');
        canvas.className = 'world-canvas';
        layer.appendChild(canvas);
        document.body.insertBefore(layer, document.body.firstChild);

        var relief = fitSky();
        var reduced = Boolean(motionQuery && motionQuery.matches);

        /* Camera pan from the previous page's place to this one. */
        var place = parseFloat(body.dataset.place);
        place = isNaN(place) ? 0 : place;

        var storedPan = parseFloat(readStorage('sessionStorage', PAN_KEY));
        var panFrom = isNaN(storedPan) ? place - 0.45 : storedPan;
        var panDistance = Math.min(Math.abs(place - panFrom), 4);
        var panDuration = isNaN(storedPan)
            ? 1800
            : 900 + 260 * panDistance;
        var panStart = null;
        var pan = reduced ? place : panFrom;

        function currentPan(now) {
            if (panStart === null) {
                panStart = now;
            }

            if (reduced || panDistance < 0.001) {
                return place;
            }

            var t = clamp((now - panStart) / panDuration, 0, 1);

            return mix(panFrom, place, easeInOutCubic(t));
        }

        function rememberPan() {
            writeStorage('sessionStorage', PAN_KEY, String(pan));
        }

        window.addEventListener('pagehide', rememberPan);

        var gl = canvas.getContext('webgl', {
            alpha: false,
            antialias: false,
            depth: false,
            stencil: false,
            powerPreference: 'low-power',
            preserveDrawingBuffer: false
        });

        if (!gl || !style) {
            root.classList.add('world-static');
            return;
        }

        var program = createProgram(gl);

        if (!program) {
            root.classList.add('world-static');
            return;
        }

        gl.useProgram(program);

        var buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(
            gl.ARRAY_BUFFER,
            new Float32Array([-1, -1, 3, -1, -1, 3]),
            gl.STATIC_DRAW
        );

        var position = gl.getAttribLocation(program, 'aPosition');
        gl.enableVertexAttribArray(position);
        gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0);

        var uniforms = {};
        [
            'uRes', 'uTime', 'uSun', 'uMouse', 'uPan', 'uWater', 'uRelief',
            'uLantern', 'uLanternLit', 'uLanternCount'
        ].concat(Object.keys(style.palette)).forEach(function (name) {
            uniforms[name] = gl.getUniformLocation(program, name);
        });

        // Flat shapes need crisp edges, so render near full size; weak devices get fewer pixels.
        var lowPower = (navigator.hardwareConcurrency || 4) <= 2
            || (navigator.connection && navigator.connection.saveData);
        var renderScale = lowPower ? 0.6 : 1;
        var maxPixels = lowPower ? 900000 : 2400000;

        function resize() {
            var width = window.innerWidth;
            var height = window.innerHeight;
            var scale = renderScale * Math.min(window.devicePixelRatio || 1, 1.5);

            if (width * height * scale * scale > maxPixels) {
                scale = Math.sqrt(maxPixels / (width * height));
            }

            canvas.width = Math.max(2, Math.round(width * scale));
            canvas.height = Math.max(2, Math.round(height * scale));
            gl.viewport(0, 0, canvas.width, canvas.height);
        }

        function uploadPalette() {
            gl.uniform2f(uniforms.uSun, style.sun[0], style.sun[1]);

            Object.keys(style.palette).forEach(function (name) {
                gl.uniform3fv(uniforms[name], hexToRgb(style.palette[name]));
            });
        }

        /* ------------------------------------------------------------
         * Lanterns: one per participant, released from the near shore
         * ---------------------------------------------------------- */
        var lanternData = new Float32Array(MAX_LANTERNS * 4);
        var lanternLitData = new Float32Array(MAX_LANTERNS);
        var lanternList = [];
        var lanternsActive = false;
        var NEVER_LIT = 1000;

        function lanternSeed(index) {
            var s = Math.sin(index * 12.9898 + 4.1) * 43758.5453;

            return s - Math.floor(s);
        }

        function setLanterns(total, lit) {
            var now = performance.now();

            total = clamp(Math.round(total) || 0, 0, MAX_LANTERNS);
            lit = clamp(Math.round(lit) || 0, 0, total);

            while (lanternList.length < total) {
                var index = lanternList.length;
                var a = lanternSeed(index);
                var b = lanternSeed(index + 31);

                lanternList.push({
                    // Spread across the lake; depth is a share of the open water.
                    x: 0.1 + 0.8 * ((index * 0.618 + a * 0.3) % 1),
                    depth: 0.15 + 0.85 * b,
                    drift: (a - 0.5) * 0.004,
                    phase: a * 6.283,
                    born: now,
                    litAt: null,
                    brightness: 0,
                    target: 0
                });
            }

            lanternList.length = total;
            lanternList.forEach(function (lantern, index) {
                var isLit = index < lit;

                if (isLit && lantern.litAt === null) {
                    lantern.litAt = now;
                } else if (!isLit) {
                    lantern.litAt = null;
                }

                lantern.target = isLit ? 1 : 0;
            });
            lanternsActive = total > 0;
            wake();
        }

        function openWater() {
            var height = window.innerHeight || 1;
            var depth = uWaterValue - 0.02;

            if (lanternFloor) {
                var waterY = (1 - uWaterValue) * height;

                depth = (lanternFloor.getBoundingClientRect().top - waterY) / height - 0.03;
            }

            return clamp(depth, 0.04, 0.45);
        }

        function uploadLanterns(now, seconds) {
            var maxDepth = openWater();
            var animating = false;

            lanternList.forEach(function (lantern, index) {
                var age = (now - lantern.born) / 1000;
                var litAge = lantern.litAt === null ? NEVER_LIT : (now - lantern.litAt) / 1000;
                // Released near the viewer, then carried out to its resting place.
                var release = reduced ? 1 : easeInOutCubic(clamp(age / 5, 0, 1));
                var depth = mix(maxDepth, 0.02 + lantern.depth * (maxDepth - 0.02), release);
                var x = lantern.x + Math.sin(seconds * 0.05 + lantern.phase) * 0.012
                    + lantern.drift * Math.sin(seconds * 0.02);
                var bob = Math.sin(seconds * 0.9 + lantern.phase) * 0.0015;

                lantern.brightness += (lantern.target - lantern.brightness) * 0.08;
                animating = animating || release < 1 || litAge < 3
                    || Math.abs(lantern.target - lantern.brightness) > 0.01;

                lanternData[index * 4] = x;
                lanternData[index * 4 + 1] = depth + bob;
                lanternData[index * 4 + 2] = 0.009 + depth * 0.055;
                lanternData[index * 4 + 3] = lantern.brightness;
                lanternLitData[index] = reduced ? NEVER_LIT : litAge;
            });

            gl.uniform4fv(uniforms.uLantern, lanternData);
            gl.uniform1fv(uniforms.uLanternLit, lanternLitData);
            gl.uniform1f(uniforms.uLanternCount, lanternList.length);

            return animating;
        }

        window.ARWorld = {
            setLanterns: setLanterns
        };
        document.dispatchEvent(new Event('ar-world-ready'));

        /*
         * Scene time continues across page loads (seconds within the current
         * hour), so clouds and birds do not jump back when navigating.
         */
        var timeOrigin = (Date.now() / 1000) % 3600;
        var startedAt = performance.now();
        var sceneTime = timeOrigin;

        var mouseTarget = 0;
        var mouse = 0;
        var uWaterValue = waterLevel();
        var frameHandle = 0;
        var lastFrame = -Infinity;
        var forceDraw = true;
        var drawn = false;
        var IDLE_INTERVAL = 1000 / (style.frameRate || 30);

        var paused = reduced || readStorage('localStorage', MOTION_KEY) === 'paused';

        function draw(now) {
            if (!paused) {
                sceneTime = timeOrigin + (now - startedAt) / 1000;
            }

            mouse += (mouseTarget - mouse) * 0.06;
            pan = currentPan(now);
            uWaterValue = waterLevel();

            gl.uniform2f(uniforms.uRes, canvas.width, canvas.height);
            gl.uniform1f(uniforms.uTime, sceneTime);
            gl.uniform1f(uniforms.uMouse, mouse);
            gl.uniform1f(uniforms.uPan, pan);
            gl.uniform1f(uniforms.uWater, uWaterValue);
            gl.uniform1f(uniforms.uRelief, relief);

            var lanternsMoving = lanternsActive && uploadLanterns(now, sceneTime);

            gl.drawArrays(gl.TRIANGLES, 0, 3);

            if (!drawn) {
                drawn = true;
                root.classList.add('world-ready');
            }

            return lanternsMoving;
        }

        var lastWater = null;
        var scrolling = false;

        function isSettling(now) {
            return Math.abs(mouseTarget - mouse) > 0.002
                || (!reduced && panStart !== null && now - panStart < panDuration)
                || panStart === null
                || scrolling;
        }

        /*
         * Animates continuously while playing. While paused it only renders
         * while the camera, scroll or pointer are still moving, then goes idle.
         * Scrolling and panning draw every frame so the waterline never lags
         * behind the menu.
         */
        var lanternsMoving = false;

        function loop(now) {
            frameHandle = 0;

            var water = waterLevel();
            var fast = water !== lastWater || isSettling(now) || lanternsMoving;

            lastWater = water;
            scrolling = false;

            if (forceDraw || fast || now - lastFrame >= IDLE_INTERVAL) {
                forceDraw = false;
                lastFrame = now;
                lanternsMoving = draw(now);
            }

            if (!document.hidden && (!paused || isSettling(now) || lanternsMoving)) {
                frameHandle = window.requestAnimationFrame(loop);
            }
        }

        function wake() {
            if (!frameHandle && !document.hidden) {
                frameHandle = window.requestAnimationFrame(loop);
            }
        }

        resize();
        uploadPalette();
        gl.uniform1f(uniforms.uLanternCount, 0);
        wake();

        window.addEventListener('resize', function () {
            resize();
            relief = fitSky();
            forceDraw = true;
            wake();
        });

        // Fonts change the title's height; refit once they are in.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                relief = fitSky();
                forceDraw = true;
                wake();
            });
        }

        window.addEventListener('scroll', function () {
            scrolling = true;
            wake();
        }, {passive: true});

        if (!reduced) {
            window.addEventListener('pointermove', function (event) {
                mouseTarget = (event.clientX / window.innerWidth - 0.5) * 2;
                wake();
            }, {passive: true});
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                forceDraw = true;
                wake();
            }
        });

        // Returning through the back button restores this page as it was left.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                forceDraw = true;
                wake();
            }
        });

        canvas.addEventListener('webglcontextlost', function (event) {
            event.preventDefault();
            window.cancelAnimationFrame(frameHandle);
            frameHandle = 0;
            root.classList.add('world-static');
        });

        function setPaused(value) {
            paused = Boolean(value);

            if (!paused) {
                startedAt = performance.now() - (sceneTime - timeOrigin) * 1000;
                wake();
            }

            root.classList.toggle('world-paused', paused);
            updateToggle();
        }

        var toggle = null;

        function updateToggle() {
            if (!toggle) {
                return;
            }

            var label = paused ? 'Play background animation' : 'Pause background animation';

            toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
            toggle.setAttribute('aria-label', label);
            toggle.title = label;
            toggle.innerHTML = paused
                ? '<i class="ti ti-player-play" aria-hidden="true"></i>'
                : '<i class="ti ti-player-pause" aria-hidden="true"></i>';
        }

        if (!reduced) {
            toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'world-toggle';
            toggle.addEventListener('click', function () {
                setPaused(!paused);
                writeStorage('localStorage', MOTION_KEY, paused ? 'paused' : 'playing');
            });

            var skyBar = document.querySelector('.sky-bar-end');

            if (skyBar) {
                skyBar.appendChild(toggle);
            } else {
                document.body.appendChild(toggle);
            }
        }

        setPaused(paused);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

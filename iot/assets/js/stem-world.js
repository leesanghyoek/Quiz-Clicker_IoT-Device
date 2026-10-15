/*
 * STEM space
 * ----------
 * A bright study space behind the UI, drawn with three.js: a stack of books,
 * an atom with orbiting electrons, a light bulb, a graduation cap, a pencil,
 * a lab flask, the sigma and pi signs, and the classroom's own answer device
 * with its A-D buttons float and turn slowly among a few small solids.
 *
 * They lean toward the pointer, drift with scrolling, and move a short way to
 * new places when the page changes (each page has a place, <body data-place>).
 * On classroom screens every participant is a glossy bead between the title
 * and the panel below; a bead lights up and sends out a ring when that
 * student answers (ARWorld.setLanterns(total, lit), called by motion.js).
 *
 * Runs only when world.js picked the "stem" style (<html data-world-style>).
 */
(function () {
    'use strict';

    var root = document.documentElement;

    if (root.dataset.worldStyle !== 'stem' || !window.THREE) {
        return;
    }

    var THREE = window.THREE;
    var PAN_KEY = 'ar-world-pan';
    var MOTION_KEY = 'ar-world-motion';
    var ARRIVED_KEY = 'ar-stem-arrived';
    var SCENE_KEY = 'ar-stem-scene';
    var SNAPSHOT_KEY = 'ar-stem-snapshot';  // also read by the inline script in includes/layout_start.php
    var GLIDE_SECONDS = 0.7;                // how unhurried objects drift to a new page's spots
    var GLIDE_MAX_SPEED = 0.3;              // screen units per second (the screen is 2 units wide)
    var SKY_LINE = 0.45;                    // where object spots are measured from (screen units, -1 bottom to 1 top)
    var PAGE_DRIFT = 0.07;                  // how far a page or tab moves the objects (screen units)
    var PAGE_TURN = 0.45;                   // and how much it turns them (radians)
    var MAX_BEADS = 40;

    var COLORS = {
        blue: 0x2b59c3,
        navy: 0x13204a,
        yellow: 0xffcf3f,
        tomato: 0xf25c3b,
        green: 0x2bb673,
        sky: 0x5ec8f2,
        violet: 0x7a5cf0,
        cream: 0xfff6e3,
        paper: 0xfbfcff,
        silver: 0xc9d1e3,
        wood: 0xe8b27a,
        pink: 0xff8fa3
    };

    var motionQuery = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;
    var reduced = Boolean(motionQuery && motionQuery.matches);

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function mix(a, b, t) {
        return a + (b - a) * t;
    }

    /*
     * Move state[key] towards target like a critically damped spring: it
     * speeds up gently from rest, slows down before arriving and never
     * overshoots. The speed lives in state['v' + key] and stays under maxSpeed,
     * so a long way is travelled slowly rather than in a rush.
     */
    function smoothDamp(state, key, target, smoothTime, maxSpeed, dt) {
        var speedKey = 'v' + key;
        var omega = 2 / smoothTime;
        var x = omega * dt;
        var decay = 1 / (1 + x + 0.48 * x * x + 0.235 * x * x * x);
        var maxChange = maxSpeed * smoothTime;
        var change = clamp(state[key] - target, -maxChange, maxChange);
        var goal = state[key] - change;
        var temp = (state[speedKey] + omega * change) * dt;
        var next = goal + (change + temp) * decay;

        state[speedKey] = (state[speedKey] - omega * temp) * decay;

        // Never run past the real target.
        if ((target - state[key] > 0) === (next > target)) {
            next = target;
            state[speedKey] = 0;
        }
        state[key] = next;
    }

    function easeOutBack(t) {
        var c = 1.4;

        return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2);
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
            // Without storage the scene simply starts fresh on every page.
        }
    }

    function material(color, options) {
        return new THREE.MeshStandardMaterial(Object.assign({
            color: color,
            roughness: 0.36,
            metalness: 0.02,
            envMapIntensity: 0.7
        }, options || {}));
    }

    /* ---------------------------------------------------------------
     * The objects
     * ------------------------------------------------------------- */
    function makeBooks() {
        var group = new THREE.Group();

        [
            {color: COLORS.blue, y: 0, turn: 0},
            {color: COLORS.tomato, y: 0.46, turn: -0.16},
            {color: COLORS.green, y: 0.92, turn: 0.12}
        ].forEach(function (spec) {
            var book = new THREE.Group();
            var cover = material(spec.color);
            var bottom = new THREE.Mesh(new THREE.BoxGeometry(2.6, 0.07, 1.9), cover);
            var top = bottom.clone();
            var spine = new THREE.Mesh(new THREE.BoxGeometry(0.08, 0.44, 1.9), cover);
            var pages = new THREE.Mesh(
                new THREE.BoxGeometry(2.46, 0.3, 1.78),
                material(COLORS.cream, {roughness: 0.85})
            );

            bottom.position.y = -0.185;
            top.position.y = 0.185;
            spine.position.x = -1.26;
            pages.position.x = 0.04;
            book.add(bottom, top, spine, pages);
            book.position.y = spec.y;
            book.rotation.y = spec.turn;
            group.add(book);
        });

        group.position.y = -0.46;
        return wrap(group);
    }

    function makeAtom() {
        var group = new THREE.Group();
        var electrons = [];

        group.add(new THREE.Mesh(new THREE.SphereGeometry(0.5, 32, 16), material(COLORS.tomato)));

        for (var i = 0; i < 3; i++) {
            var orbit = new THREE.Group();
            var electron = new THREE.Mesh(
                new THREE.SphereGeometry(0.17, 20, 12),
                material(COLORS.yellow, {emissive: COLORS.yellow, emissiveIntensity: 0.35})
            );

            orbit.rotation.set(1.15, i * 1.05, i * 0.4);
            orbit.add(new THREE.Mesh(
                new THREE.TorusGeometry(1.7, 0.045, 12, 96),
                material(COLORS.blue, {metalness: 0.25, roughness: 0.3})
            ));
            orbit.add(electron);
            electrons.push({mesh: electron, phase: i * 2.1, speed: 1.3 + i * 0.25});
            group.add(orbit);
        }

        var item = wrap(group);

        item.tick = function (seconds) {
            electrons.forEach(function (electron) {
                var angle = seconds * electron.speed + electron.phase;

                electron.mesh.position.set(Math.cos(angle) * 1.7, Math.sin(angle) * 1.7, 0);
            });
        };

        return item;
    }

    function makeBulb() {
        var group = new THREE.Group();
        var glassProfile = [
            [0.42, 0], [0.46, 0.25], [0.64, 0.55], [0.9, 0.86], [1.0, 1.2],
            [0.95, 1.56], [0.75, 1.86], [0.42, 2.05], [0, 2.12]
        ].map(function (p) {
            return new THREE.Vector2(p[0], p[1]);
        });
        var glassMaterial = material(0xffd84d, {
            emissive: 0xffb300,
            emissiveIntensity: 0.3,
            roughness: 0.12,
            transparent: true,
            opacity: 0.94
        });
        var glass = new THREE.Mesh(new THREE.LatheGeometry(glassProfile, 40), glassMaterial);
        var metal = material(COLORS.silver, {metalness: 0.6, roughness: 0.3});
        var base = new THREE.Mesh(new THREE.CylinderGeometry(0.43, 0.4, 0.55, 32), metal);
        var tip = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.14, 0.16, 24), material(COLORS.navy));

        base.position.y = -0.28;
        tip.position.y = -0.62;
        group.add(glass, base, tip);

        [-0.1, -0.27, -0.44].forEach(function (y) {
            var groove = new THREE.Mesh(new THREE.TorusGeometry(0.43, 0.035, 8, 32), metal);

            groove.rotation.x = Math.PI / 2;
            groove.position.y = y;
            group.add(groove);
        });

        group.position.y = -0.7;

        var item = wrap(group);

        item.tick = function (seconds) {
            glassMaterial.emissiveIntensity = 0.24 + 0.14 * Math.sin(seconds * 1.4);
        };

        return item;
    }

    function makeCap() {
        var group = new THREE.Group();
        var cloth = material(COLORS.navy, {roughness: 0.55});
        var board = new THREE.Mesh(new THREE.BoxGeometry(2.8, 0.12, 2.8), cloth);
        var head = new THREE.Mesh(new THREE.CylinderGeometry(1.0, 1.1, 0.75, 40), material(0x1c2c63, {roughness: 0.55}));
        var gold = material(COLORS.yellow, {metalness: 0.2});
        var button = new THREE.Mesh(new THREE.CylinderGeometry(0.15, 0.15, 0.08, 20), gold);
        var cord = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 1.95, 8), gold);
        var drop = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 0.9, 8), gold);
        var tassel = new THREE.Mesh(new THREE.ConeGeometry(0.12, 0.42, 16), gold);

        board.rotation.y = Math.PI / 4;
        board.position.y = 0.52;
        head.position.y = 0.1;
        button.position.y = 0.62;
        cord.rotation.z = Math.PI / 2;
        cord.position.set(0.97, 0.62, 0);
        drop.position.set(1.95, 0.17, 0);
        tassel.position.set(1.95, -0.42, 0);
        group.add(board, head, button, cord, drop, tassel);

        return wrap(group);
    }

    function makePencil() {
        var group = new THREE.Group();
        var body = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.24, 3.2, 6), material(COLORS.yellow));
        var wood = new THREE.Mesh(new THREE.ConeGeometry(0.24, 0.6, 6), material(COLORS.wood, {roughness: 0.7}));
        var lead = new THREE.Mesh(new THREE.ConeGeometry(0.08, 0.2, 12), material(COLORS.navy));
        var ferrule = new THREE.Mesh(
            new THREE.CylinderGeometry(0.255, 0.255, 0.32, 20),
            material(COLORS.silver, {metalness: 0.6, roughness: 0.28})
        );
        var eraser = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.24, 0.36, 20), material(COLORS.pink));

        wood.rotation.x = Math.PI;
        wood.position.y = -1.9;
        lead.rotation.x = Math.PI;
        lead.position.y = -2.12;
        ferrule.position.y = 1.76;
        eraser.position.y = 2.1;
        group.add(body, wood, lead, ferrule, eraser);
        group.rotation.z = -0.9;

        return wrap(group);
    }

    function makeFlask() {
        var group = new THREE.Group();
        var profile = [
            [0, 0], [0.95, 0], [1.0, 0.08], [0.32, 1.55], [0.3, 2.2], [0.38, 2.28], [0.38, 2.36]
        ].map(function (p) {
            return new THREE.Vector2(p[0], p[1]);
        });
        var liquidProfile = [[0, 0.06], [0.9, 0.06], [0.62, 0.72], [0, 0.72]].map(function (p) {
            return new THREE.Vector2(p[0], p[1]);
        });
        var glass = new THREE.Mesh(
            new THREE.LatheGeometry(profile, 40),
            material(0xdff4ff, {transparent: true, opacity: 0.42, roughness: 0.05, depthWrite: false})
        );
        var liquid = new THREE.Mesh(
            new THREE.LatheGeometry(liquidProfile, 40),
            material(COLORS.green, {emissive: COLORS.green, emissiveIntensity: 0.18})
        );
        var bubbles = [];

        group.add(liquid, glass);

        for (var i = 0; i < 4; i++) {
            var bubble = new THREE.Mesh(
                new THREE.SphereGeometry(0.06 + i * 0.015, 12, 8),
                material(0xffffff, {transparent: true, opacity: 0.85})
            );

            bubbles.push({mesh: bubble, phase: i / 4, x: (i - 1.5) * 0.15});
            group.add(bubble);
        }

        group.position.y = -1.1;

        var item = wrap(group);

        item.tick = function (seconds) {
            bubbles.forEach(function (bubble) {
                var t = (seconds * 0.35 + bubble.phase) % 1;

                bubble.mesh.position.set(bubble.x * (1 - t), 0.15 + t * 2.1, 0);
                bubble.mesh.visible = t < 0.95;
            });
        };

        return item;
    }

    function extruded(points, color, holes) {
        var shape = new THREE.Shape(points.map(function (p) {
            return new THREE.Vector2(p[0], p[1]);
        }));

        (holes || []).forEach(function (hole) {
            shape.holes.push(new THREE.Path(hole.map(function (p) {
                return new THREE.Vector2(p[0], p[1]);
            })));
        });

        var geometry = new THREE.ExtrudeGeometry(shape, {
            depth: 0.42,
            bevelEnabled: true,
            bevelThickness: 0.07,
            bevelSize: 0.06,
            bevelSegments: 3
        });

        geometry.center();

        return new THREE.Mesh(geometry, material(color));
    }

    function makeSigma() {
        return wrap(extruded([
            [-0.8, 1], [0.8, 1], [0.8, 0.62], [-0.16, 0.62], [0.34, 0],
            [-0.16, -0.62], [0.8, -0.62], [0.8, -1], [-0.8, -1], [-0.8, -0.72],
            [-0.06, 0], [-0.8, 0.72]
        ], COLORS.violet));
    }

    function makePi() {
        return wrap(extruded([
            [-0.95, 0.72], [-0.95, 1.0], [0.95, 1.0], [0.95, 0.72], [0.52, 0.72],
            [0.52, -0.68], [0.64, -0.8], [0.82, -0.78], [0.82, -1.0], [0.46, -1.0],
            [0.26, -0.8], [0.26, 0.72], [-0.28, 0.72], [-0.42, -1.0], [-0.72, -1.0],
            [-0.54, 0.72]
        ], COLORS.tomato));
    }

    function makeSetSquare() {
        var mesh = extruded(
            [[-1.3, -1.0], [1.3, -1.0], [-1.3, 1.4]],
            COLORS.sky,
            [[[-0.95, -0.66], [0.25, -0.66], [-0.95, 0.44]]]
        );

        mesh.material.transparent = true;
        mesh.material.opacity = 0.78;

        return wrap(mesh);
    }

    function letterTexture(letter) {
        var canvas = document.createElement('canvas');
        var context = canvas.getContext('2d');

        canvas.width = 64;
        canvas.height = 64;
        context.fillStyle = '#ffffff';
        context.font = '700 40px Lexend, "Segoe UI", sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText(letter, 32, 35);

        var texture = new THREE.CanvasTexture(canvas);

        texture.encoding = THREE.sRGBEncoding;
        return texture;
    }

    /* The classroom's answer device: a case with buttons A, B, C and D. */
    function makeDevice() {
        var group = new THREE.Group();
        var outline = new THREE.Shape();
        var w = 1.5;
        var h = 1.0;
        var r = 0.3;

        outline.moveTo(-w + r, -h);
        outline.lineTo(w - r, -h);
        outline.quadraticCurveTo(w, -h, w, -h + r);
        outline.lineTo(w, h - r);
        outline.quadraticCurveTo(w, h, w - r, h);
        outline.lineTo(-w + r, h);
        outline.quadraticCurveTo(-w, h, -w, h - r);
        outline.lineTo(-w, -h + r);
        outline.quadraticCurveTo(-w, -h, -w + r, -h);

        var caseGeometry = new THREE.ExtrudeGeometry(outline, {
            depth: 0.42,
            bevelEnabled: true,
            bevelThickness: 0.06,
            bevelSize: 0.06,
            bevelSegments: 3
        });
        var shell = new THREE.Mesh(caseGeometry, material(COLORS.navy, {roughness: 0.45}));

        shell.rotation.x = -Math.PI / 2;
        shell.position.y = -0.24;
        group.add(shell);

        var buttons = [];
        var bezel = material(COLORS.silver, {metalness: 0.6, roughness: 0.3});

        ['A', 'B', 'C', 'D'].forEach(function (letter, index) {
            var color = [COLORS.tomato, COLORS.blue, COLORS.green, COLORS.yellow][index];
            var x = -1.02 + index * 0.68;
            var cap = new THREE.Group();
            var key = new THREE.Mesh(new THREE.CylinderGeometry(0.27, 0.27, 0.2, 28), material(color));
            var label = new THREE.Mesh(
                new THREE.CircleGeometry(0.2, 24),
                new THREE.MeshBasicMaterial({map: letterTexture(letter), transparent: true})
            );
            var ring = new THREE.Mesh(new THREE.TorusGeometry(0.31, 0.035, 8, 28), bezel);

            label.rotation.x = -Math.PI / 2;
            label.position.y = 0.105;
            cap.add(key, label);
            cap.position.set(x, 0.32, 0.28);
            ring.rotation.x = Math.PI / 2;
            ring.position.set(x, 0.25, 0.28);
            group.add(cap, ring);
            buttons.push(cap);
        });

        var screen = new THREE.Mesh(new THREE.BoxGeometry(1.5, 0.04, 0.42), material(0x0b1230, {roughness: 0.15}));
        var ledMaterial = material(COLORS.green, {emissive: COLORS.green, emissiveIntensity: 0.4});
        var led = new THREE.Mesh(new THREE.SphereGeometry(0.08, 16, 10), ledMaterial);
        var antenna = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 0.8, 8), bezel);

        screen.position.set(-0.2, 0.25, -0.5);
        led.position.set(1.15, 0.27, -0.55);
        antenna.position.set(1.3, 0.62, -0.8);
        group.add(screen, led, antenna);
        group.rotation.x = 0.55;

        var item = wrap(group);
        var press = {button: -1, at: 0};

        // Every few seconds a student presses an answer and the device sends it.
        item.press = function (seconds) {
            press.button = Math.floor(Math.random() * 4);
            press.at = seconds;
        };

        item.tick = function (seconds) {
            if (seconds - press.at > 3.2 + Math.random() * 0.1) {
                item.press(seconds);
            }

            var t = seconds - press.at;

            buttons.forEach(function (button, index) {
                var down = index === press.button && t < 0.3 ? Math.sin((t / 0.3) * Math.PI) : 0;

                button.position.y = 0.32 - down * 0.09;
            });
            ledMaterial.emissiveIntensity = t < 0.6 ? 1.4 : 0.35;
        };

        return item;
    }

    function makeSolid(kind, color) {
        var geometry;

        if (kind === 'cube') {
            geometry = new THREE.BoxGeometry(0.8, 0.8, 0.8);
        } else if (kind === 'ico') {
            geometry = new THREE.IcosahedronGeometry(0.55, 0);
        } else if (kind === 'torus') {
            geometry = new THREE.TorusGeometry(0.42, 0.16, 16, 40);
        } else if (kind === 'cone') {
            geometry = new THREE.ConeGeometry(0.45, 0.85, 4);
        } else if (kind === 'octa') {
            geometry = new THREE.OctahedronGeometry(0.55, 0);
        } else {
            geometry = new THREE.SphereGeometry(0.42, 28, 16);
        }

        return wrap(new THREE.Mesh(geometry, material(color, {flatShading: kind === 'ico' || kind === 'octa'})));
    }

    /* Each object sits in a holder so its own spin and the layout can be set separately. */
    function wrap(object) {
        var holder = new THREE.Group();

        holder.add(object);

        return {holder: holder, object: object};
    }

    /* ---------------------------------------------------------------
     * Scene
     * ------------------------------------------------------------- */
    function buildEnvironment(renderer) {
        var envScene = new THREE.Scene();
        var sphere = new THREE.SphereGeometry(10, 32, 16);
        var colors = [];
        var position = sphere.attributes.position;
        var top = new THREE.Color(0xf4f8ff);
        var bottom = new THREE.Color(0xc9d6ff);

        for (var i = 0; i < position.count; i++) {
            var t = (position.getY(i) / 10 + 1) / 2;
            var c = bottom.clone().lerp(top, t);

            colors.push(c.r, c.g, c.b);
        }

        sphere.setAttribute('color', new THREE.Float32BufferAttribute(colors, 3));
        envScene.add(new THREE.Mesh(sphere, new THREE.MeshBasicMaterial({vertexColors: true, side: THREE.BackSide})));

        [[-5, 6, 5], [6, 3, 6]].forEach(function (p) {
            var panel = new THREE.Mesh(
                new THREE.PlaneGeometry(5, 2.5),
                new THREE.MeshBasicMaterial({color: 0xffffff, side: THREE.DoubleSide})
            );

            panel.position.set(p[0], p[1], p[2]);
            panel.lookAt(0, 0, 0);
            envScene.add(panel);
        });

        var generator = new THREE.PMREMGenerator(renderer);
        var texture = generator.fromScene(envScene, 0.04).texture;

        generator.dispose();
        return texture;
    }

    // Colours are given in sRGB and lit in linear space, so they stay saturated.
    THREE.ColorManagement.legacyMode = false;

    function start() {
        var body = document.body;
        var layer = document.createElement('div');
        var renderer;

        try {
            renderer = new THREE.WebGLRenderer({antialias: true, alpha: true, powerPreference: 'low-power'});
        } catch (error) {
            root.classList.add('world-static');
            return;
        }

        var canvas = renderer.domElement;
        var lowPower = (navigator.hardwareConcurrency || 4) <= 2
            || (navigator.connection && navigator.connection.saveData);

        layer.className = 'world-layer';
        layer.setAttribute('aria-hidden', 'true');
        canvas.className = 'world-canvas';
        layer.appendChild(canvas);
        body.insertBefore(layer, body.firstChild);

        renderer.setClearColor(0x000000, 0);
        renderer.outputEncoding = THREE.sRGBEncoding;
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, lowPower ? 1 : 1.75));

        var scene = new THREE.Scene();
        var camera = new THREE.PerspectiveCamera(35, 1, 0.1, 100);

        camera.position.set(0, 0, 30);
        scene.environment = buildEnvironment(renderer);
        scene.add(new THREE.HemisphereLight(0xffffff, 0xc3cdf5, 0.55));

        var key = new THREE.DirectionalLight(0xffffff, 1.25);

        key.position.set(-6, 10, 12);
        scene.add(key);

        var warm = new THREE.DirectionalLight(0xffe2b8, 0.35);

        warm.position.set(8, -4, 6);
        scene.add(warm);

        var stage = new THREE.Group();

        scene.add(stage);

        /*
         * Where each object rests. x runs across the screen from -1 to 1. y is
         * measured in the sky above the menu: 0 at the menu, 1 at the top of
         * the screen, negative values continue down the margins beside the
         * panel. Most objects keep right of the title and to the page edges.
         * "calm" spots are used on classroom screens, in plain screen units.
         */
        var items = [
            {make: makeBooks, x: 0.72, y: 0.23, z: 0, size: 1, calm: [-0.36, 0.84]},
            {make: makeAtom, x: 0.9, y: -0.08, z: -1, size: 1},
            {make: makeBulb, x: 0.42, y: 0.74, z: -2, size: 0.95, calm: [0.36, 0.84]},
            {make: makeCap, x: -0.9, y: 0.23, z: -1, size: 0.9},
            {make: makeDevice, x: 0.88, y: -0.59, z: 1, size: 1, calm: [0.95, -0.3]},
            {make: makeFlask, x: -0.9, y: -0.65, z: 0, size: 0.95, calm: [-0.95, -0.34]},
            {make: makeSigma, x: 0.2, y: 0.2, z: -3, size: 0.9, compact: true, calm: [0.0, 0.86]},
            {make: makePi, x: -0.42, y: 0.19, z: -2, size: 0.8, compact: true},
            {make: makePencil, x: -0.2, y: 0.76, z: 1.5, size: 0.85},
            {make: makeSetSquare, x: -0.62, y: 0.86, z: -4, size: 0.8, compact: true},
            {make: function () { return makeSolid('cube', COLORS.blue); }, x: 0.98, y: 0.58, z: -2, size: 1},
            {make: function () { return makeSolid('ico', COLORS.sky); }, x: 0.7, y: 0.8, z: -5, size: 1, compact: true},
            {make: function () { return makeSolid('torus', COLORS.yellow); }, x: -0.93, y: -0.28, z: 2, size: 1},
            {make: function () { return makeSolid('cone', COLORS.green); }, x: -0.68, y: 0.52, z: 2, size: 1},
            {make: function () { return makeSolid('octa', COLORS.violet); }, x: -0.97, y: 0.5, z: -2, size: 1},
            {make: function () { return makeSolid('sphere', COLORS.tomato); }, x: -0.04, y: 0.42, z: -6, size: 1, compact: true}
        ].map(function (spec, index) {
            var made = spec.make();

            made.spec = spec;
            made.index = index;
            made.spin = {
                x: (Math.sin(index * 12.9) * 0.5) * 0.12,
                y: 0.12 + (index % 3) * 0.05,
                phase: index * 1.7
            };
            stage.add(made.holder);
            return made;
        });

        /* ------------------------------------------------------------
         * Layout: scroll and pointer
         *
         * The objects are a layer of their own behind the page: their spots
         * depend only on the window, never on the page's text or section, so
         * changing page or tab never pushes them around.
         * ---------------------------------------------------------- */
        var place = parseFloat(body.dataset.place);
        place = isNaN(place) ? 0 : place;

        /*
         * A number for this page and tab (not the record shown), so each page
         * shifts and turns the objects a little, always the same way. The
         * objects drift there slowly from where the last page left them.
         */
        var pageSeed = (function () {
            var key = location.pathname.split('/').pop() + '|' + (new URLSearchParams(location.search).get('tab') || '');
            var hash = 0;

            for (var i = 0; i < key.length; i++) {
                hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
            }

            return (hash % 1000) / 1000 * Math.PI * 2;
        })();

        var clockStart = performance.now();
        var arrived = readStorage('sessionStorage', ARRIVED_KEY) === '1';

        writeStorage('sessionStorage', ARRIVED_KEY, '1');
        window.addEventListener('pagehide', function () {
            // The lake styles (world.js) pan from here if the style is switched.
            writeStorage('sessionStorage', PAN_KEY, String(place));
        });

        var viewport = {width: 1, height: 1, compact: false};

        // Classroom screens are full of large text: only a few objects stay, in spots kept clear.
        var calm = body.classList.contains('lobby-page') || body.classList.contains('quiz-run-page');

        function halfHeightAt(z) {
            return Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * (camera.position.z - z);
        }

        function resize() {
            viewport.width = window.innerWidth || 1;
            viewport.height = window.innerHeight || 1;
            viewport.compact = viewport.width < 720;
            camera.aspect = viewport.width / viewport.height;
            camera.updateProjectionMatrix();
            renderer.setSize(viewport.width, viewport.height, false);
        }

        /* Where an object rests, in screen units. */
        function restingPoint(item) {
            var spec = item.spec;
            var sx;
            var sy;

            if (calm && spec.calm) {
                sx = spec.calm[0];
                sy = spec.calm[1];
            } else {
                sx = spec.x;
                sy = spec.y >= 0 ? SKY_LINE + spec.y * (1 - SKY_LINE) : SKY_LINE + spec.y * (SKY_LINE + 1);
            }

            if (viewport.compact && !calm) {
                // Phones: keep objects to the edges and towards the top.
                sx = Math.sign(sx || 1) * Math.max(Math.abs(sx), 0.7);
                sy = Math.max(sy, 0.05);
            }

            var i = item.index;

            return {
                sx: sx + PAGE_DRIFT * Math.sin(pageSeed + i * 2.3),
                sy: sy + PAGE_DRIFT * 0.6 * Math.cos(pageSeed * 1.3 + i * 1.1),
                turn: PAGE_TURN * Math.sin(pageSeed * 0.7 + i * 1.9),
                z: spec.z
            };
        }

        function scaleFor(spec) {
            return viewport.compact ? 0.62 : (calm ? 0.75 : 1);
        }

        var pointer = {x: 0, y: 0, tx: 0, ty: 0};

        window.addEventListener('pointermove', function (event) {
            pointer.tx = (event.clientX / viewport.width - 0.5) * 2;
            pointer.ty = (event.clientY / viewport.height - 0.5) * 2;
            wake();
        }, {passive: true});

        window.addEventListener('scroll', wake, {passive: true});

        /* ------------------------------------------------------------
         * Beads for participants on classroom screens
         * ---------------------------------------------------------- */
        var beadGeometry = new THREE.SphereGeometry(0.34, 28, 18);
        var ringGeometry = new THREE.TorusGeometry(0.34, 0.04, 8, 40);
        var beads = [];
        var anchor = document.querySelector('[data-waterline-anchor]');
        var floor = document.querySelector('[data-lantern-floor]');

        function beadMaterial() {
            return material(COLORS.paper, {roughness: 0.22, emissive: COLORS.yellow, emissiveIntensity: 0});
        }

        function setLanterns(total, lit) {
            var now = performance.now();

            total = clamp(Math.round(total) || 0, 0, MAX_BEADS);
            lit = clamp(Math.round(lit) || 0, 0, total);

            while (beads.length < total) {
                var mesh = new THREE.Mesh(beadGeometry, beadMaterial());
                var ring = new THREE.Mesh(ringGeometry, new THREE.MeshBasicMaterial({
                    color: COLORS.yellow,
                    transparent: true,
                    opacity: 0
                }));

                scene.add(mesh, ring);
                beads.push({mesh: mesh, ring: ring, born: now, litAt: null, glow: 0, index: beads.length});
            }

            while (beads.length > total) {
                var gone = beads.pop();

                scene.remove(gone.mesh, gone.ring);
            }

            beads.forEach(function (bead, index) {
                var isLit = index < lit;

                if (isLit && bead.litAt === null) {
                    bead.litAt = now;
                } else if (!isLit) {
                    bead.litAt = null;
                }
            });

            wake();
        }

        /* The band between the title and the panel below, in world units at depth 2. */
        function beadBand() {
            var top = anchor ? anchor.getBoundingClientRect().top : viewport.height * 0.6;
            var bottom = floor ? floor.getBoundingClientRect().top : viewport.height * 0.85;
            var half = halfHeightAt(2);
            var toWorld = function (py) {
                return (0.5 - py / viewport.height) * 2 * half;
            };

            return {top: toWorld(top + 24), bottom: toWorld(bottom - 24), half: half};
        }

        function placeBeads(now, seconds) {
            if (!beads.length) {
                return false;
            }

            var band = beadBand();
            var width = band.half * camera.aspect * 2 * 0.8;
            var rows = beads.length > 20 ? 2 : 1;
            var perRow = Math.ceil(beads.length / rows);
            var moving = false;

            beads.forEach(function (bead) {
                var row = Math.floor(bead.index / perRow);
                var column = bead.index % perRow;
                var count = Math.min(perRow, beads.length - row * perRow);
                var x = -width / 2 + width * (column + 0.5) / count;
                var middle = mix(band.top, band.bottom, rows === 1 ? 0.5 : 0.3 + row * 0.4);
                var y = middle + Math.sin(bead.index * 1.7) * Math.abs(band.top - band.bottom) * 0.12;
                var age = (now - bead.born) / 1000;
                var arrive = reduced ? 1 : easeOutBack(clamp(age / 1.1, 0, 1));

                y = mix(band.bottom - 2, y, arrive) + (reduced ? 0 : Math.sin(seconds * 1.6 + bead.index) * 0.08);
                bead.mesh.position.set(x, y, 2);
                bead.mesh.scale.setScalar(Math.max(0.01, arrive));

                var target = bead.litAt === null ? 0 : 1;

                bead.glow += (target - bead.glow) * 0.12;
                bead.mesh.material.color.setHex(COLORS.paper).lerp(new THREE.Color(COLORS.yellow), bead.glow);
                bead.mesh.material.emissiveIntensity = bead.glow * 0.45;

                var litAge = bead.litAt === null ? 99 : (now - bead.litAt) / 1000;
                var ringT = clamp(litAge / 1.4, 0, 1);

                bead.ring.position.copy(bead.mesh.position);
                bead.ring.scale.setScalar(1 + ringT * 2.4);
                bead.ring.material.opacity = reduced ? 0 : (1 - ringT) * 0.9;

                moving = moving || arrive < 1 || Math.abs(target - bead.glow) > 0.01 || ringT < 1;
            });

            return moving;
        }

        window.ARWorld = {
            setLanterns: setLanterns
        };
        document.dispatchEvent(new Event('ar-world-ready'));

        /*
         * Fade an object in or out with its materials' opacity (it keeps its
         * size), remembering each material's own opacity the first time.
         */
        function fade(item, amount) {
            if (!item.materials) {
                item.materials = [];
                item.holder.traverse(function (node) {
                    [].concat(node.material || []).forEach(function (mat) {
                        item.materials.push({mat: mat, opacity: mat.opacity, transparent: mat.transparent});
                    });
                });
            }

            amount = clamp(amount, 0, 1);
            if (item.faded === amount) {
                return;
            }
            item.faded = amount;
            item.materials.forEach(function (entry) {
                var see = entry.transparent || amount < 0.999;

                entry.mat.opacity = entry.opacity * amount;
                if (entry.mat.transparent !== see) {
                    entry.mat.transparent = see;
                    entry.mat.needsUpdate = true;
                }
            });
        }

        /* ------------------------------------------------------------
         * Drawing loop
         * ---------------------------------------------------------- */
        var paused = reduced || readStorage('localStorage', MOTION_KEY) === 'paused';
        var frame = 0;
        var drawn = false;
        var sceneSeconds = 0;
        var lastNow = performance.now();

        /*
         * Every page (and every tab, which is a page too) starts a new script,
         * so the scene is handed over through sessionStorage: the clock keeps
         * turning the objects from where they were, and each object glides
         * from where it was to where this page wants it, instead of jumping.
         */
        var handed = null;

        try {
            handed = JSON.parse(readStorage('sessionStorage', SCENE_KEY) || 'null');
        } catch (error) {
            handed = null;
        }

        if (handed && typeof handed.seconds === 'number' && Array.isArray(handed.items)) {
            sceneSeconds = handed.seconds + (handed.paused ? 0 : clamp((Date.now() - handed.at) / 1000, 0, 2));

            if (Array.isArray(handed.pointer)) {
                pointer.x = pointer.tx = handed.pointer[0] || 0;
                pointer.y = pointer.ty = handed.pointer[1] || 0;
            }

            items.forEach(function (item, index) {
                var was = handed.items[index];

                if (Array.isArray(was)) {
                    item.shown = {
                        sx: was[0], sy: was[1], vis: was[2], vsx: was[3] || 0, vsy: was[4] || 0, vvis: was[5] || 0,
                        turn: was[6] || 0, vturn: was[7] || 0
                    };
                }
            });
        }

        window.addEventListener('pagehide', function () {
            var round = function (value) {
                return Math.round(value * 1000) / 1000;
            };

            writeStorage('sessionStorage', SCENE_KEY, JSON.stringify({
                at: Date.now(),
                seconds: round(sceneSeconds),
                paused: paused,
                pointer: [round(pointer.x), round(pointer.y)],
                items: items.map(function (item) {
                    var shown = item.shown;

                    return shown
                        ? [shown.sx, shown.sy, shown.vis, shown.vsx, shown.vsy, shown.vvis, shown.turn, shown.vturn].map(round)
                        : null;
                })
            }));
            saveSnapshot();
        });

        /*
         * A picture of the scene for the next page to show while its own scene
         * is still loading, so the objects do not blink out between pages.
         */
        function saveSnapshot() {
            if (!drawn || reduced) {
                return;
            }

            try {
                renderer.render(scene, camera);

                var width = Math.min(canvas.width, 1280);
                var height = Math.round(canvas.height * width / canvas.width);
                var small = document.createElement('canvas');

                small.width = width;
                small.height = height;
                small.getContext('2d').drawImage(canvas, 0, 0, width, height);

                var url = small.toDataURL('image/webp', 0.8);

                if (url.indexOf('data:image/webp') !== 0) {
                    url = small.toDataURL('image/png');
                }
                writeStorage('sessionStorage', SNAPSHOT_KEY, JSON.stringify({
                    at: Date.now(),
                    w: viewport.width,
                    h: viewport.height,
                    url: url
                }));
            } catch (error) {
                // No picture: the next page just fades its scene in as before.
            }
        }

        function draw(now) {
            var delta = Math.min(0.05, (now - lastNow) / 1000);

            lastNow = now;

            if (!paused) {
                sceneSeconds += delta;
            }

            pointer.x += (pointer.tx - pointer.x) * 0.06;
            pointer.y += (pointer.ty - pointer.y) * 0.06;

            var scrollShift = reduced ? 0 : window.scrollY * 0.35;
            var settling = Math.abs(pointer.tx - pointer.x) > 0.002
                || Math.abs(pointer.ty - pointer.y) > 0.002;

            items.forEach(function (item) {
                var rest = restingPoint(item);
                var half = halfHeightAt(rest.z);
                var shiftY = scrollShift * (2 * half / viewport.height) * (0.6 + rest.z * 0.08);
                var entrance = 1;

                if (!arrived && !reduced) {
                    entrance = easeOutBack(clamp((now - clockStart - item.index * 70) / 900, 0, 1));
                    settling = settling || entrance < 1;
                }

                var hidden = (calm
                    // Narrow classroom screens have no room along the top.
                    ? !item.spec.calm || (viewport.compact && item.spec.calm[1] > 0.5)
                    : viewport.compact && item.spec.compact);
                var bob = reduced ? 0 : Math.sin(sceneSeconds * 0.8 + item.spin.phase) * 0.18;
                var goal = {sx: rest.sx, sy: rest.sy, vis: hidden ? 0 : 1, turn: rest.turn, vsx: 0, vsy: 0, vvis: 0, vturn: 0};
                var shown = item.shown;

                // Drift (and fade in or out) towards this page's spot, like a spring, rather than jumping.
                if (!shown || reduced) {
                    item.shown = shown = goal;
                } else {
                    smoothDamp(shown, 'sx', goal.sx, GLIDE_SECONDS, GLIDE_MAX_SPEED, delta);
                    smoothDamp(shown, 'sy', goal.sy, GLIDE_SECONDS, GLIDE_MAX_SPEED, delta);
                    smoothDamp(shown, 'vis', goal.vis, GLIDE_SECONDS * 0.6, 3, delta);
                    smoothDamp(shown, 'turn', goal.turn, GLIDE_SECONDS * 1.4, 0.5, delta);
                    shown.vis = clamp(shown.vis, 0, 1);
                    settling = settling
                        || Math.abs(goal.sx - shown.sx) + Math.abs(shown.vsx) > 0.001
                        || Math.abs(goal.sy - shown.sy) + Math.abs(shown.vsy) > 0.001
                        || Math.abs(goal.vis - shown.vis) + Math.abs(shown.vvis) > 0.005
                        || Math.abs(goal.turn - shown.turn) + Math.abs(shown.vturn) > 0.002;
                }

                item.holder.visible = shown.vis > 0.01;
                item.holder.position.set(
                    shown.sx * half * camera.aspect + pointer.x * 0.35 * (1 + rest.z * 0.05),
                    shown.sy * half + shiftY + bob - pointer.y * 0.25,
                    rest.z
                );
                item.holder.scale.setScalar(item.spec.size * scaleFor(item.spec) * Math.max(0.001, entrance));
                fade(item, shown.vis);
                item.holder.rotation.set(
                    0.25 + pointer.y * 0.25 + Math.sin(sceneSeconds * 0.3 + item.spin.phase) * 0.15,
                    shown.turn + sceneSeconds * item.spin.y + pointer.x * 0.35,
                    Math.sin(sceneSeconds * 0.25 + item.index) * 0.12
                );

                if (item.tick && !paused) {
                    item.tick(sceneSeconds);
                }
            });

            var beadsMoving = placeBeads(now, sceneSeconds);

            renderer.render(scene, camera);

            if (!drawn) {
                drawn = true;

                var picture = document.getElementById('world-snapshot');

                if (picture) {
                    root.classList.add('world-instant');
                    window.requestAnimationFrame(function () {
                        picture.remove();
                    });
                }
                root.classList.add('world-ready');
            }

            return settling || beadsMoving;
        }

        var scrollSeen = window.scrollY;

        function loop(now) {
            frame = 0;

            var active = draw(now);
            var scrolled = window.scrollY !== scrollSeen;

            scrollSeen = window.scrollY;

            if (!document.hidden && (!paused || active || scrolled)) {
                frame = window.requestAnimationFrame(loop);
            }
        }

        function wake() {
            if (!frame && !document.hidden) {
                lastNow = performance.now();
                frame = window.requestAnimationFrame(loop);
            }
        }

        resize();
        wake();

        window.addEventListener('resize', function () {
            resize();
            wake();
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                wake();
            }
        });

        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                wake();
            }
        });

        canvas.addEventListener('webglcontextlost', function (event) {
            event.preventDefault();
            window.cancelAnimationFrame(frame);
            frame = 0;
            root.classList.add('world-static');
        });

        /* Pause button, same place and behaviour as in the lake styles. */
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
                paused = !paused;
                writeStorage('localStorage', MOTION_KEY, paused ? 'paused' : 'playing');
                root.classList.toggle('world-paused', paused);
                updateToggle();
                wake();
            });

            var skyBar = document.querySelector('.sky-bar-end');

            if (skyBar) {
                skyBar.appendChild(toggle);
            } else {
                body.appendChild(toggle);
            }

            updateToggle();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

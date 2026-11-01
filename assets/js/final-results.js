(() => {
    'use strict';
    const root = document.getElementById('finalResults');
    if (!root) return;
    const winners = [...root.querySelectorAll('[data-place]')].sort((a, b) => Number(b.dataset.place) - Number(a.dataset.place));
    if (!winners.length) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const announcement = document.getElementById('resultsAnnouncement');
    const standings = document.getElementById('resultsStandings');
    const standingsToggle = document.getElementById('resultsStandingsToggle');
    const skip = document.getElementById('resultsSkip');
    const replay = document.getElementById('resultsReplay');
    const runners = document.getElementById('resultsRunners');
    const audio = document.getElementById('resultsAudio');
    const music = document.getElementById('resultsMusic');
    const canvas = document.getElementById('resultsConfetti');
    const stage = document.getElementById('ceremonyStage');
    const afterparty = document.getElementById('resultsAfterparty');
    const fullscreen = document.getElementById('resultsFullscreen');
    const context = canvas.getContext('2d');
    let timers = [];
    let animationFrame = 0;
    let playing = false;
    let musicEnabled = true;
    let musicRequest = 0;
    let cue = null;

    function playCue(filename) {
        if (!musicEnabled || !audio) return;
        if (cue) cue.pause();
        cue = new Audio(new URL(filename, audio.src).href);
        cue.volume = .55;
        cue.play().catch(() => {});
    }

    function stopConfetti() {
        cancelAnimationFrame(animationFrame);
        if (context) context.clearRect(0, 0, canvas.width, canvas.height);
    }

    function celebrate() {
        stopConfetti();
        if (reducedMotion.matches || !context) return;
        const isReview = root.dataset.review === 'true';
        const width = window.innerWidth;
        const height = window.innerHeight;
        const scale = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = width * scale;
        canvas.height = height * scale;
        context.setTransform(scale, 0, 0, scale, 0, 0);
        const colors = ['#ffcf46', '#fff', '#ed619b', '#59cfdf', '#a88aee'];
        const pieceCount = isReview ? 35 : 110;
        const maxDuration = isReview ? 4500 : 14000;
        const respawnDuration = isReview ? 3000 : 12000;
        const pieces = Array.from({ length: pieceCount }, () => ({
            x: Math.random() * width, y: -30 - Math.random() * height * 2,
            speed: 100 + Math.random() * 170, drift: (Math.random() - .5) * 80,
            rotation: Math.random() * Math.PI, color: colors[Math.floor(Math.random() * colors.length)]
        }));
        let last = performance.now();
        const start = last;
        function draw(now) {
            const elapsed = Math.min((now - last) / 1000, .05);
            last = now;
            context.clearRect(0, 0, width, height);
            context.globalAlpha = Math.min(1, Math.max(0, (maxDuration - (now - start)) / 1500));
            pieces.forEach(piece => {
                piece.x += piece.drift * elapsed;
                piece.y += piece.speed * elapsed;
                piece.rotation += elapsed * 2;
                if (piece.y > height + 20 && now - start < respawnDuration) {
                    piece.y = -20;
                    piece.x = Math.random() * width;
                }
                context.save();
                context.translate(piece.x, piece.y);
                context.rotate(piece.rotation);
                context.fillStyle = piece.color;
                context.fillRect(-4, -6, 8, 12);
                context.restore();
            });
            if (now - start < maxDuration) animationFrame = requestAnimationFrame(draw);
            else stopConfetti();
        }
        animationFrame = requestAnimationFrame(draw);
    }

    function setStandings(visible) {
        standings.hidden = !visible;
        standingsToggle.setAttribute('aria-expanded', String(visible));
        standingsToggle.textContent = visible ? 'Hide full standings' : 'View full standings';
    }

    function clearTimers() {
        timers.forEach(clearTimeout);
        timers = [];
    }

    function finish(withConfetti = false) {
        clearTimers();
        playing = false;
        root.classList.remove('results-animating', 'is-spotlighting', 'is-celebrating');
        root.dataset.ceremonyPhase = 'complete';
        winners.forEach(winner => {
            winner.removeAttribute('aria-hidden');
            winner.querySelector('.results-winner-identity')?.removeAttribute('aria-hidden');
            winner.classList.add('is-revealed', 'is-settled');
        });
        afterparty.hidden = false;
        if (runners) runners.hidden = false;
        if (skip) skip.hidden = true;
        if (replay) replay.hidden = false;
        if (skip && document.activeElement === skip) replay.focus();
        const champion = winners.find(winner => winner.dataset.place === '1');
        announcement.textContent = champion.dataset.nickname + ' takes first place!';
        if (withConfetti) celebrate();
    }

    function reveal(place, phase) {
        root.dataset.ceremonyPhase = phase;
        winners.filter(winner => Number(winner.dataset.place) > place).forEach(winner => winner.classList.add('is-settled'));
        const winner = winners.find(entry => Number(entry.dataset.place) === place);
        winner.classList.add('is-revealed');
        winner.removeAttribute('aria-hidden');
        const label = { 1: 'First', 2: 'Second', 3: 'Third' }[place];
        announcement.textContent = label + ' place' + (place !== 1 && winner.dataset.nickname ? ': ' + winner.dataset.nickname + '!' : '.');
        if (place !== 1) playCue('podium-' + place + (place === 2 ? 'nd' : 'rd') + '.mp3');
    }

    function start() {
        clearTimers();
        stopConfetti();
        if (cue) cue.pause();
        root.classList.remove('is-spotlighting', 'is-celebrating');
        if (reducedMotion.matches) {
            finish();
            return;
        }
        playing = true;
        setStandings(false);
        root.classList.add('results-animating');
        root.dataset.ceremonyPhase = 'intro';
        afterparty.hidden = true;
        winners.forEach(winner => {
            winner.classList.remove('is-revealed', 'is-settled');
            winner.setAttribute('aria-hidden', 'true');
            if (winner.dataset.place === '1') winner.querySelector('.results-winner-identity')?.setAttribute('aria-hidden', 'true');
        });
        if (runners) runners.hidden = true;
        skip.hidden = false;
        replay.hidden = true;
        announcement.textContent = 'And the winners are...';
        if (musicEnabled && audio) {
            audio.currentTime = 0;
            audio.play().catch(() => {
                const playOnGesture = () => {
                    if (musicEnabled && audio && audio.paused) {
                        audio.play().catch(() => {});
                    }
                };
                window.addEventListener('click', playOnGesture, { once: true });
                window.addEventListener('keydown', playOnGesture, { once: true });
            });
        }
        // Match the reference: each step rises in the center before moving aside.
        timers.push(setTimeout(() => reveal(3, 'third'), 1400));
        timers.push(setTimeout(() => reveal(2, 'second'), 5000));
        timers.push(setTimeout(() => reveal(1, 'first'), 8200));
        timers.push(setTimeout(() => {
            root.dataset.ceremonyPhase = 'spotlight';
            root.classList.add('is-spotlighting');
            announcement.textContent = 'And our champion is...';
        }, 9500));
        timers.push(setTimeout(() => {
            root.classList.remove('is-spotlighting');
            root.classList.add('is-celebrating');
            root.dataset.ceremonyPhase = 'celebrate';
            const champion = winners.find(winner => winner.dataset.place === '1');
            champion.querySelector('.results-winner-identity')?.removeAttribute('aria-hidden');
            announcement.textContent = champion.dataset.nickname + ' takes first place!';
            playCue('podium-1st.mp3');
            celebrate();
        }, 12700));
        timers.push(setTimeout(() => finish(), 14500));
    }

    standingsToggle.hidden = false;
    standingsToggle.addEventListener('click', () => {
        const shouldShow = standings.hidden;
        if (playing) finish();
        setStandings(shouldShow);
        if (shouldShow) standings.scrollIntoView({ behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'start' });
    });

    if (root.dataset.host === 'true') {
        skip.addEventListener('click', () => {
            finish(true);
            replay.focus();
        });
        replay.addEventListener('click', () => {
            stage.scrollIntoView({ behavior: 'auto', block: 'start' });
            start();
            if (playing) skip.focus();
        });
        music.hidden = false;
        audio.volume = .35;
        function updateMusic(enabled) {
            musicEnabled = enabled;
            music.textContent = enabled ? 'Music: On' : 'Music: Off';
            music.setAttribute('aria-pressed', String(enabled));
        }
        updateMusic(true);
        music.addEventListener('click', async () => {
            const request = ++musicRequest;
            if (musicEnabled) {
                audio.pause();
                if (cue) cue.pause();
                updateMusic(false);
                return;
            }
            updateMusic(true);
            try {
                await audio.play();
            } catch (_) {
                if (request === musicRequest) {
                    updateMusic(false);
                    announcement.textContent = 'Music is unavailable. You can still enjoy the podium.';
                }
            }
        });
        audio.addEventListener('error', () => updateMusic(false));
        if (document.fullscreenEnabled && stage.requestFullscreen) {
            fullscreen.hidden = false;
            fullscreen.addEventListener('click', async () => {
                try {
                    if (document.fullscreenElement) await document.exitFullscreen();
                    else await root.requestFullscreen();
                } catch (_) {
                    announcement.textContent = 'Fullscreen is unavailable in this browser.';
                }
            });
            document.addEventListener('fullscreenchange', () => {
                fullscreen.setAttribute('aria-label', document.fullscreenElement ? 'Exit fullscreen' : 'Enter fullscreen');
            });
        }
        start();
    } else {
        // A player's result is available immediately, even if they arrive after the host.
        setStandings(true);
        celebrate();
    }

    reducedMotion.addEventListener('change', () => {
        if (reducedMotion.matches) {
            if (playing) finish();
            stopConfetti();
        }
    });
    window.addEventListener('resize', stopConfetti);
    window.addEventListener('pagehide', () => {
        clearTimers();
        stopConfetti();
        if (audio) audio.pause();
        if (cue) cue.pause();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            finish();
            if (music) {
                musicEnabled = true;
                music.textContent = 'Music: On';
                music.setAttribute('aria-pressed', 'true');
            }
        }
    });
})();
(function () {
    'use strict';
    const panel = document.getElementById('hostPlayers');
    if (!panel) return;
    const container = document.getElementById('playersContainer');
    const message = document.getElementById('kickPlayerStatus');
    let previousPlayers = '';
    let pending = false;
    let fetching = false;
    let revision = 0;

    async function refreshPlayers() {
        if (fetching || pending) return;
        fetching = true;
        const requestedRevision = revision;
        try {
            const response = await fetch(panel.dataset.apiUrl + 'game_state.php?action=get_players&id_room=' + panel.dataset.roomId, {cache: 'no-store'});
            const data = await response.json();
            if (!response.ok || !data.success || pending || requestedRevision !== revision) return;
            data.players.sort((a, b) => {
                const aTeam = (a.team_id && a.team_id > 0) ? Number(a.team_id) : 999;
                const bTeam = (b.team_id && b.team_id > 0) ? Number(b.team_id) : 999;
                if (aTeam !== bTeam) return aTeam - bTeam;
                return Number(a.id_player) - Number(b.id_player);
            });
            const signature = JSON.stringify(data.players.map(p => [p.id_player, p.nickname, p.team_id, p.character_svg]));
            if (signature !== previousPlayers) {
                previousPlayers = signature;
                container.replaceChildren();
                data.players.forEach(function (player) {
                    const card = document.createElement('div');
                    card.className = 'host-player-card';
                    if (player.team_id && player.team_color) {
                        card.style.borderLeft = '4px solid ' + player.team_color;
                    }
                    const avatar = document.createElement('span');
                    avatar.className = 'host-player-avatar';
                    if (player.character_svg) {
                        const avatarDocument = new DOMParser().parseFromString(player.character_svg, 'image/svg+xml');
                        const avatarSvg = avatarDocument.documentElement;
                        if (avatarSvg && avatarSvg.localName === 'svg' && !avatarDocument.querySelector('parsererror')) {
                            avatar.appendChild(document.importNode(avatarSvg, true));
                        }
                    }
                    const name = document.createElement('span');
                    name.className = 'host-player-name';
                    name.textContent = player.nickname;
                    if (player.team_id && player.team_short_name) {
                        const teamBadge = document.createElement('span');
                        teamBadge.className = 'badge ml-1 shadow-sm font-weight-bold';
                        teamBadge.style.background = player.team_color || '#1368ce';
                        teamBadge.style.color = '#ffffff';
                        teamBadge.style.fontSize = '0.72rem';
                        teamBadge.style.borderRadius = '8px';
                        teamBadge.style.padding = '2px 6px';
                        teamBadge.innerHTML = '<i class="' + (player.team_icon || 'fas fa-users') + ' mr-1"></i>' + player.team_short_name;
                        name.appendChild(teamBadge);
                    }
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'kick-player';
                    button.dataset.playerId = player.id_player;
                    button.dataset.playerName = player.nickname;
                    button.setAttribute('aria-label', 'Kick ' + player.nickname);
                    button.title = 'Kick ' + player.nickname;
                    button.textContent = 'Kick';
                    card.append(avatar, name, button);
                    container.appendChild(card);
                });
            }
            const waitingEl = document.getElementById('waitingMsg'); if (waitingEl) waitingEl.hidden = data.players.length > 0;
            document.dispatchEvent(new CustomEvent('roomplayerschange', {detail: data}));
        } catch (error) {
            // Retry on the next poll; retain the current player list.
        } finally {
            fetching = false;
        }
    }

    panel.addEventListener('click', async function (event) {
        const button = event.target.closest('.kick-player');
        if (!button || pending) return;
        if (!window.confirm('Remove "' + button.dataset.playerName + '" from this game?')) return;
        pending = true;
        revision++;
        button.disabled = true;
        message.textContent = 'Removing player...';
        try {
            const response = await fetch(panel.dataset.apiUrl + 'kick_player.php', {
                method: 'POST',
                body: new URLSearchParams({id_room: panel.dataset.roomId, id_player: button.dataset.playerId, csrf_token: panel.dataset.csrfToken})
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to remove player.');
            message.textContent = data.message;
            if (panel.dataset.reloadAfterKick === '1') window.location.reload();
        } catch (error) {
            message.textContent = error.message || 'Unable to remove player. Please try again.';
        } finally {
            pending = false;
            button.disabled = false;
            refreshPlayers();
        }
    });
    refreshPlayers();
    window.setInterval(refreshPlayers, 1500);
})();

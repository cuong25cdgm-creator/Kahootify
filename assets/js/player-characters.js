(() => {
    'use strict';
    document.querySelectorAll('[data-character-editor]').forEach(editor => {
        const preview = editor.querySelector('[data-character-preview]');
        const summary = editor.querySelector('[data-character-summary]');
        const tabs = [...editor.querySelectorAll('[data-character-tab]')];
        const panels = tabs.map(tab => document.getElementById(tab.dataset.characterTab));
        const random = editor.querySelector('[data-character-random]');
        const characters = [...editor.querySelectorAll('[name="character_id"]')];
        const accessories = [...editor.querySelectorAll('[name="accessory_id"]')];

        function updatePreview() {
            const character = characters.find(input => input.checked);
            const accessory = accessories.find(input => input.checked);
            if (!character || !accessory) return;
            const svg = character.closest('label').querySelector('svg').cloneNode(true);
            svg.classList.add('character-preview-avatar');
            const layer = svg.querySelector('.avatar-accessory');
            layer.replaceChildren();
            if (accessory.value) {
                const image = document.createElementNS('http://www.w3.org/2000/svg', 'image');
                image.setAttribute('href', accessory.dataset.src);
                image.setAttribute('width', '100');
                image.setAttribute('height', '100');
                layer.append(image);
            }
            const label = character.dataset.name + (accessory.value ? ' with ' + accessory.dataset.name : '');
            svg.setAttribute('aria-label', label);
            preview.replaceChildren(svg);
            summary.textContent = label;
        }

        function selectTab(index, focus = false) {
            tabs.forEach((tab, i) => {
                tab.setAttribute('aria-selected', String(i === index));
                tab.tabIndex = i === index ? 0 : -1;
                panels[i].hidden = i !== index;
            });
            if (focus) tabs[index].focus();
        }
        editor.querySelector('[role="tablist"]').hidden = false;
        panels.forEach((panel, i) => {
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tabs[i].id);
        });
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => selectTab(index));
            tab.addEventListener('keydown', event => {
                if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                    event.preventDefault();
                    selectTab(event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + 1) % tabs.length, true);
                }
            });
        });
        editor.addEventListener('change', updatePreview);
        random.hidden = false;
        random.addEventListener('click', () => {
            characters[Math.floor(Math.random() * characters.length)].checked = true;
            accessories[Math.floor(Math.random() * accessories.length)].checked = true;
            updatePreview();
        });
        selectTab(0);
    });

    const form = document.getElementById('playerJoinForm');
    if (!form) return;
    const nickname = form.querySelector('[name="nickname"]');
    const nameStep = document.getElementById('nicknameStep');
    const appearanceStep = document.getElementById('appearanceStep');
    const teamStep = document.getElementById('teamStep');
    const selectedTeamInput = document.getElementById('selectedTeamId');
    const hasTeamStep = !!teamStep;

    const next = document.getElementById('joinContinue');
    const back = document.getElementById('joinBack');
    const submit = document.getElementById('joinSubmit');
    const heading = document.getElementById('joinHeading');
    const description = document.getElementById('joinDescription');
    const steps = document.getElementById('joinStepLabel');

    let currentStep = 0;

    function updateStepUI(stepIndex, focus = true) {
        currentStep = stepIndex;

        if (hasTeamStep) {
            teamStep.hidden = (stepIndex !== 0);
            nameStep.hidden = (stepIndex !== 1);
            appearanceStep.hidden = (stepIndex !== 2);

            back.hidden = (stepIndex === 0);
            next.hidden = (stepIndex === 0 || stepIndex === 2);
            submit.hidden = (stepIndex !== 2);

            if (stepIndex === 0) {
                heading.textContent = 'Chọn đội của bạn';
                description.textContent = 'Chọn một đội để gia nhập trước khi đặt tên.';
                steps.textContent = '1 / 3 · Chọn đội';
            } else if (stepIndex === 1) {
                heading.textContent = 'Enter your nickname';
                description.textContent = 'This is how you will appear on the big screen.';
                steps.textContent = '2 / 3 · Tên người chơi';
                if (focus && nickname) nickname.focus();
            } else if (stepIndex === 2) {
                heading.textContent = 'Make it yours!';
                description.textContent = 'Pick a character, add an accessory, and get ready to play.';
                steps.textContent = '3 / 3 · Nhân vật';
                if (focus) {
                    const tab = document.getElementById('charactersTab');
                    if (tab) tab.focus();
                }
            }
        } else {
            nameStep.hidden = (stepIndex !== 0);
            appearanceStep.hidden = (stepIndex !== 1);

            back.hidden = (stepIndex === 0);
            next.hidden = (stepIndex === 1);
            submit.hidden = (stepIndex !== 1);

            if (stepIndex === 0) {
                heading.textContent = 'Enter your nickname';
                description.textContent = 'This is how you will appear on the big screen.';
                steps.textContent = '1 / 2 · Your nickname';
                if (focus && nickname) nickname.focus();
            } else {
                heading.textContent = 'Make it yours!';
                description.textContent = 'Pick a character, add an accessory, and get ready to play.';
                steps.textContent = '2 / 2 · Your character';
                if (focus) {
                    const tab = document.getElementById('charactersTab');
                    if (tab) tab.focus();
                }
            }
        }
    }

    if (hasTeamStep) {
        const teamCards = teamStep.querySelectorAll('.team-option-card');
        teamCards.forEach(card => {
            card.addEventListener('click', () => {
                if (card.dataset.isFull === '1') return;

                teamCards.forEach(c => {
                    c.classList.remove('is-selected');
                    c.style.borderColor = '#e0e0e0';
                    c.style.boxShadow = 'none';
                });

                card.classList.add('is-selected');
                const color = card.style.getPropertyValue('--team-color') || '#1368ce';
                card.style.borderColor = color;
                card.style.boxShadow = '0 0 0 3px ' + color;

                if (selectedTeamInput) {
                    selectedTeamInput.value = card.dataset.teamId;
                }

                setTimeout(() => {
                    updateStepUI(1, true);
                }, 180);
            });
        });
    }

    next.addEventListener('click', () => {
        if (hasTeamStep && currentStep === 0) {
            if (!selectedTeamInput || !selectedTeamInput.value) {
                alert('Vui lòng chọn một đội tham gia!');
                return;
            }
            updateStepUI(1, true);
        } else if ((hasTeamStep && currentStep === 1) || (!hasTeamStep && currentStep === 0)) {
            nickname.value = nickname.value.trim();
            if (nickname.reportValidity()) {
                updateStepUI(hasTeamStep ? 2 : 1, true);
            }
        }
    });

    back.addEventListener('click', () => {
        if (currentStep > 0) {
            updateStepUI(currentStep - 1, true);
        }
    });

    form.addEventListener('submit', event => {
        const lastStep = hasTeamStep ? 2 : 1;
        if (currentStep !== lastStep) {
            event.preventDefault();
            next.click();
        }
    });

    steps.hidden = false;
    let initialStep = 0;
    if (form.dataset.hasError === 'true') {
        if (hasTeamStep && selectedTeamInput && selectedTeamInput.value) {
            initialStep = nickname.value.trim() !== '' ? 2 : 1;
        } else if (!hasTeamStep && nickname.value.trim() !== '') {
            initialStep = 1;
        }
    }
    updateStepUI(initialStep, false);
})();

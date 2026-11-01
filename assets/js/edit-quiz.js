var EQ = window.KAHOOT_EDIT;
var editingQuestionId = null;
var preferredQuestionType = null;

document.addEventListener('DOMContentLoaded', function () {
    var qTypeEl = document.getElementById('propQuestionType');
    if (qTypeEl) {
        preferredQuestionType = qTypeEl.value;
    }
    initSortable();
    initPropertiesPanel();
    initQuizTitleEdit();
    initQuestionMediaUpload();
    initAnswerTiles();
    initTextAnswerPreview();
    initFormControls();
    initFormValidation();
    syncPropsToForm();
});

function initSortable() {
    var listEl = document.getElementById('questionListSortable');
    if (!listEl || typeof Sortable === 'undefined') return;
    Sortable.create(listEl, {
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        filter: '.q-list-actions, .q-list-actions *',
        preventOnFilter: false,
        onEnd: function () {
            updateQuestionNumberLabels();
            var items = listEl.querySelectorAll('.q-list-item');
            var order = [];
            items.forEach(function (item, idx) {
                order.push({ id: parseInt(item.dataset.id, 10), order: idx });
            });
            fetch(EQ.baseUrl + 'modules/api/reorder_questions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ quiz_id: EQ.quizId, order: order })
            });
        }
    });
}

function updateQuestionNumberLabels() {
    var listEl = document.getElementById('questionListSortable');
    if (!listEl) return;
    var items = listEl.querySelectorAll('.q-list-item');
    items.forEach(function (item, idx) {
        var numEl = item.querySelector('.q-thumb-num');
        if (numEl) {
            numEl.textContent = 'Q' + (idx + 1);
        }
        if (editingQuestionId && item.dataset.id == editingQuestionId) {
            var label = document.getElementById('canvasModeLabel');
            if (label) {
                label.textContent = 'Editing Question ' + (idx + 1);
            }
        }
    });
}

function initPropertiesPanel() {
    var qTypeEl = document.getElementById('propQuestionType');
    if (qTypeEl) {
        preferredQuestionType = qTypeEl.value;
        qTypeEl.addEventListener('change', function () {
            preferredQuestionType = this.value;
            try {
                localStorage.setItem('kahoot_qtype_' + EQ.quizId, this.value);
            } catch (e) {}
        });
    }
    ['propQuestionType', 'propTimeLimit', 'propPoints'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', syncPropsToForm);
    });
}

function syncPropsToForm() {
    document.getElementById('hiddenQuestionType').value = document.getElementById('propQuestionType').value;
    document.getElementById('hiddenTimeLimit').value    = document.getElementById('propTimeLimit').value;
    document.getElementById('hiddenPoints').value       = document.getElementById('propPoints').value;
    applyQuestionTypeUI(document.getElementById('propQuestionType').value);
}

function applyQuestionTypeUI(type) {
    var mc  = document.getElementById('answersMultipleChoice');
    var txt = document.getElementById('answersText');
    if (mc)  mc.style.display  = (type === 'multiple_choice') ? 'block' : 'none';
    if (txt) txt.style.display = (type === 'text')            ? 'block' : 'none';
}

function initQuizTitleEdit() {
    var display   = document.getElementById('quizTitleDisplay');
    var input     = document.getElementById('quizTitleInput');
    var icon      = document.getElementById('editTitleIcon');

    function startEdit() {
        input.value = display.textContent.trim();
        display.style.display = 'none';
        input.style.display = 'inline-block';
        input.focus();
        input.select();
    }

    function commitEdit() {
        var newName = input.value.trim();
        input.style.display = 'none';
        display.style.display = '';
        if (!newName || newName === display.textContent.trim()) return;
        fetch(EQ.baseUrl + 'modules/api/update_quiz_name.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ quiz_id: EQ.quizId, name: newName })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) {
                display.textContent = newName;
                document.title = 'Edit Quiz: ' + newName;
            }
        });
    }

    display.addEventListener('click', startEdit);
    icon.addEventListener('click', startEdit);
    input.addEventListener('blur', commitEdit);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { input.value = display.textContent.trim(); input.style.display = 'none'; display.style.display = ''; }
    });
}

function initQuestionMediaUpload() {
    var area        = document.getElementById('questionMediaArea');
    var fileInput   = document.getElementById('questionMediaFile');
    var preview     = document.getElementById('questionMediaPreview');
    var placeholder = document.getElementById('questionMediaPlaceholder');
    var removeBtn   = document.getElementById('questionMediaRemoveBtn');
    var removeFlag  = document.getElementById('questionMediaRemoveFlag');

    area.addEventListener('dragover', function (e) { e.preventDefault(); area.classList.add('drag-over'); });
    area.addEventListener('dragleave', function () { area.classList.remove('drag-over'); });
    area.addEventListener('drop', function (e) {
        e.preventDefault();
        area.classList.remove('drag-over');
        var f = e.dataTransfer.files[0];
        if (f) { fileInput.files = e.dataTransfer.files; showQMediaPreview(f); }
    });

    fileInput.addEventListener('change', function () {
        if (this.files[0]) showQMediaPreview(this.files[0]);
    });

    removeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        fileInput.value = '';
        preview.innerHTML = '';
        preview.style.display = 'none';
        placeholder.style.display = '';
        removeBtn.style.display = 'none';
        removeFlag.value = '1';
        area.classList.remove('has-preview');
    });

    function showQMediaPreview(file) {
        removeFlag.value = '0';
        var url = URL.createObjectURL(file);
        area.classList.add('has-preview');
        var isImage = file.type ? (file.type.indexOf('image/') === 0) : /\.(jpe?g|png|gif|webp)$/i.test(file.name);
        if (isImage) {
            preview.innerHTML = '<div class="media-preview-box"><img src="' + url + '"></div>';
        } else {
            preview.innerHTML = '<div class="media-preview-box video-preview-box" id="qVidBox"><video src="' + url + '"></video><span class="vid-play-icon"><i class="fas fa-play-circle"></i></span></div>';
            initVideoPreviewToggle('qVidBox');
        }
        preview.style.display = '';
        placeholder.style.display = 'none';
        removeBtn.style.display = '';
    }
}

function setQMediaFromUrl(url, type) {
    var preview     = document.getElementById('questionMediaPreview');
    var placeholder = document.getElementById('questionMediaPlaceholder');
    var removeBtn   = document.getElementById('questionMediaRemoveBtn');
    var area        = document.getElementById('questionMediaArea');
    if (url) {
        area.classList.add('has-preview');
        var isVideo = type === 'video' || /\.(mp4|webm)$/i.test(url);
        var fullUrl = (url.indexOf('http://') === 0 || url.indexOf('https://') === 0 || (EQ.baseUrl && url.indexOf(EQ.baseUrl) === 0)) ? url : (EQ.baseUrl + url);
        if (isVideo) {
            preview.innerHTML = '<div class="media-preview-box video-preview-box" id="qVidBox"><video src="' + fullUrl + '"></video><span class="vid-play-icon"><i class="fas fa-play-circle"></i></span></div>';
            initVideoPreviewToggle('qVidBox');
        } else {
            preview.innerHTML = '<div class="media-preview-box"><img src="' + fullUrl + '"></div>';
        }
        preview.style.display = '';
        placeholder.style.display = 'none';
        removeBtn.style.display = '';
    } else {
        area.classList.remove('has-preview');
        preview.innerHTML = '';
        preview.style.display = 'none';
        placeholder.style.display = '';
        removeBtn.style.display = 'none';
    }
}

function initAnswerTiles() {
    for (var i = 0; i < 4; i++) {
        (function (idx) {
            var toggle    = document.getElementById('answerToggle_' + idx);
            var textInput = document.getElementById('answerText_' + idx);
            var imgArea   = document.getElementById('answerImgArea_' + idx);
            var imgFile   = document.getElementById('answerImgFile_' + idx);
            var imgPreview = document.getElementById('answerImgPreview_' + idx);
            var useImg    = document.getElementById('answerUseImage_' + idx);
            var correctCb = document.getElementById('answerCorrect_' + idx);
            var tile      = document.getElementById('answerTile_' + idx);

            toggle.addEventListener('click', function () {
                if (useImg.value === '1') {
                    useImg.value = '0';
                    textInput.style.display = '';
                    imgArea.classList.remove('active');
                    toggle.textContent = '\uD83D\uDDBC Use Image';
                } else {
                    useImg.value = '1';
                    textInput.style.display = 'none';
                    imgArea.classList.add('active');
                    toggle.textContent = '\u270F\uFE0F Use Text';
                }
            });

            imgFile.addEventListener('change', function () {
                if (this.files[0]) {
                    var url = URL.createObjectURL(this.files[0]);
                    imgPreview.innerHTML = '<div class="answer-img-preview"><img src="' + url + '"></div>';
                }
            });

            correctCb.addEventListener('change', function () {
                tile.classList.toggle('is-correct', this.checked);
            });
        })(i);
    }
}

function initTextAnswerPreview() {
    var input   = document.getElementById('textAnswerInput');
    var display = document.getElementById('textAnswerUnderscores');
    if (!input || !display) return;
    input.addEventListener('input', function () {
        var val = this.value.trim();
        display.textContent = val ? Array.from(val).map(function () { return '_'; }).join(' ') : '';
    });
}

function initFormControls() {
    document.getElementById('btnCancelEdit').addEventListener('click', resetToNewMode);
    document.getElementById('btnAddNewQuestion').addEventListener('click', function () {
        if (isFormDirty()) {
            if (!confirm('Discard current changes and start a new question?')) return;
        }
        resetToNewMode();
        document.getElementById('questionTextInput').focus();
    });
}

function initFormValidation() {
    document.getElementById('questionForm').addEventListener('submit', function (e) {
        try {
            localStorage.setItem('kahoot_qtype_' + EQ.quizId, document.getElementById('propQuestionType').value);
        } catch (err) {}

        var qText = (document.getElementById('questionTextInput').value || '').trim();
        if (!qText) {
            e.preventDefault();
            alert('Please enter the question text.');
            document.getElementById('questionTextInput').focus();
            return;
        }

        var qType = document.getElementById('hiddenQuestionType').value;
        if (qType === 'text') {
            var txtAns = (document.getElementById('textAnswerInput').value || '').trim();
            if (!txtAns) {
                e.preventDefault();
                alert('Please enter the correct answer for this text question.');
                document.getElementById('textAnswerInput').focus();
            }
            return;
        }

        var validCount = 0;
        for (var i = 0; i < 4; i++) {
            var hasText  = (document.getElementById('answerText_' + i).value || '').trim() !== '';
            var usesImg  = document.getElementById('answerUseImage_' + i).value === '1';
            var hasNewImg = document.getElementById('answerImgFile_' + i).files.length > 0;
            var hasKeep  = (document.getElementById('answerKeepImage_' + i).value || '').trim() !== '';
            if (hasText || (usesImg && (hasNewImg || hasKeep))) validCount++;
        }
        if (validCount < 2) {
            e.preventDefault();
            alert('Please fill in at least 2 answer choices.');
            return;
        }
        var hasCorrect = false;
        for (var j = 0; j < 4; j++) {
            if (document.getElementById('answerCorrect_' + j).checked) { hasCorrect = true; break; }
        }
        if (!hasCorrect) {
            e.preventDefault();
            alert('Please mark at least one answer as correct.');
        }
    });
}

function isFormDirty() {
    if (editingQuestionId !== null) return true;
    if ((document.getElementById('questionTextInput').value || '').trim()) return true;
    var txtAns = document.getElementById('textAnswerInput');
    if (txtAns && (txtAns.value || '').trim()) return true;
    for (var i = 0; i < 4; i++) {
        var aText = document.getElementById('answerText_' + i);
        if (aText && (aText.value || '').trim()) return true;
        var aImg = document.getElementById('answerImgFile_' + i);
        if (aImg && aImg.files && aImg.files.length > 0) return true;
    }
    var mediaFile = document.getElementById('questionMediaFile');
    if (mediaFile && mediaFile.files && mediaFile.files.length > 0) return true;
    return false;
}

function resetToNewMode() {
    editingQuestionId = null;
    document.getElementById('formAction').value          = 'add_question';
    document.getElementById('editingQuestionId').value   = '';
    document.getElementById('questionTextInput').value   = '';
    document.getElementById('canvasModeLabel').textContent = 'New Question';
    document.getElementById('btnCancelEdit').style.display  = 'none';
    document.getElementById('btnSaveQuestion').innerHTML = '<i class="fas fa-plus mr-1"></i> Save &amp; Add Question';

    document.getElementById('questionMediaFile').value       = '';
    document.getElementById('questionMediaPreview').innerHTML = '';
    document.getElementById('questionMediaPreview').style.display  = 'none';
    document.getElementById('questionMediaPlaceholder').style.display = '';
    document.getElementById('questionMediaRemoveBtn').style.display   = 'none';
    document.getElementById('questionMediaRemoveFlag').value = '0';
    var mediaArea = document.getElementById('questionMediaArea');
    if (mediaArea) mediaArea.classList.remove('has-preview');

    for (var i = 0; i < 4; i++) {
        document.getElementById('answerText_' + i).value        = '';
        document.getElementById('answerText_' + i).style.display = '';
        document.getElementById('answerCorrect_' + i).checked   = false;
        document.getElementById('answerTile_' + i).classList.remove('is-correct');
        document.getElementById('answerUseImage_' + i).value    = '0';
        document.getElementById('answerKeepImage_' + i).value   = '';
        document.getElementById('answerImgArea_' + i).classList.remove('active');
        document.getElementById('answerImgPreview_' + i).innerHTML = '<div class="answer-img-placeholder"><i class="fas fa-image" style="color:#ccc;"></i><span>Click to upload</span></div>';
        document.getElementById('answerToggle_' + i).textContent = '\uD83D\uDDBC Use Image';
        document.getElementById('answerImgFile_' + i).value     = '';
    }

    document.getElementById('textAnswerInput').value        = '';
    document.getElementById('textAnswerUnderscores').textContent = '';

    document.querySelectorAll('.q-list-item').forEach(function (el) {
        el.classList.remove('active-edit');
    });

    if (!preferredQuestionType) {
        try {
            preferredQuestionType = localStorage.getItem('kahoot_qtype_' + EQ.quizId);
        } catch (e) {}
    }
    if (preferredQuestionType && document.getElementById('propQuestionType')) {
        document.getElementById('propQuestionType').value = preferredQuestionType;
    }
    syncPropsToForm();
}

window.loadQuestionForEdit = function (questionId) {
    if (isFormDirty()) {
        if (!confirm('Unsaved changes will be lost. Continue editing this question?')) return;
    }

    var q = null;
    for (var i = 0; i < EQ.questions.length; i++) {
        if (EQ.questions[i].id_question == questionId) { q = EQ.questions[i]; break; }
    }
    if (!q) return;

    editingQuestionId = questionId;
    document.getElementById('formAction').value        = 'update_question';
    document.getElementById('editingQuestionId').value = questionId;
    document.getElementById('questionTextInput').value = q.text || '';

    var qIdx = 0;
    document.querySelectorAll('.q-list-item').forEach(function (el, idx) {
        if (el.dataset.id == questionId) qIdx = idx + 1;
    });
    document.getElementById('canvasModeLabel').textContent = 'Editing Question ' + qIdx;
    document.getElementById('btnCancelEdit').style.display = '';
    document.getElementById('btnSaveQuestion').innerHTML   = '<i class="fas fa-check mr-1"></i> Update Question';

    var qType = q.question_type || 'multiple_choice';
    preferredQuestionType = qType;
    try {
        localStorage.setItem('kahoot_qtype_' + EQ.quizId, qType);
    } catch (e) {}
    document.getElementById('propQuestionType').value = qType;
    document.getElementById('propTimeLimit').value    = q.time_limit  || '20';
    document.getElementById('propPoints').value       = q.points      || '1000';
    syncPropsToForm();

    setQMediaFromUrl(q.image || null, q.media_type || null);
    document.getElementById('questionMediaRemoveFlag').value = '0';

    for (var i = 0; i < 4; i++) {
        document.getElementById('answerText_' + i).value        = '';
        document.getElementById('answerText_' + i).style.display = '';
        document.getElementById('answerCorrect_' + i).checked   = false;
        document.getElementById('answerTile_' + i).classList.remove('is-correct');
        document.getElementById('answerUseImage_' + i).value    = '0';
        document.getElementById('answerKeepImage_' + i).value   = '';
        document.getElementById('answerImgArea_' + i).classList.remove('active');
        document.getElementById('answerImgPreview_' + i).innerHTML = '<div class="answer-img-placeholder"><i class="fas fa-image" style="color:#ccc;"></i><span>Click to upload</span></div>';
        document.getElementById('answerToggle_' + i).textContent = '\uD83D\uDDBC Use Image';
        document.getElementById('answerImgFile_' + i).value     = '';
    }

    if (qType === 'text') {
        var ans = (q.answers || []).find(function (a) { return a.is_correct == 1; });
        var txt = ans ? (ans.text || '') : '';
        document.getElementById('textAnswerInput').value          = txt;
        document.getElementById('textAnswerUnderscores').textContent = Array.from(txt).map(function () { return '_'; }).join(' ');
    } else {
        (q.answers || []).forEach(function (ans, i) {
            if (i > 3) return;
            var isCorr = ans.is_correct == 1;
            document.getElementById('answerCorrect_' + i).checked = isCorr;
            document.getElementById('answerTile_' + i).classList.toggle('is-correct', isCorr);
            if (ans.image_path) {
                document.getElementById('answerUseImage_' + i).value  = '1';
                document.getElementById('answerText_' + i).style.display = 'none';
                document.getElementById('answerImgArea_' + i).classList.add('active');
                document.getElementById('answerImgPreview_' + i).innerHTML = '<div class="answer-img-preview"><img src="' + EQ.baseUrl + ans.image_path + '"></div>';
                document.getElementById('answerToggle_' + i).textContent   = '\u270F\uFE0F Use Text';
                document.getElementById('answerKeepImage_' + i).value      = ans.image_path;
            } else {
                document.getElementById('answerText_' + i).value = ans.text || '';
            }
        });
    }

    document.querySelectorAll('.q-list-item').forEach(function (el) {
        el.classList.toggle('active-edit', el.dataset.id == questionId);
    });

    document.querySelector('.q-canvas').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

window.duplicateQuestion = function (questionId) {
    fetch(EQ.baseUrl + 'modules/api/duplicate_question.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question_id: questionId, quiz_id: EQ.quizId })
    }).then(function (r) { return r.json(); }).then(function (data) {
        if (data.success) location.reload();
    });
};

function initVideoPreviewToggle(boxId) {
    var box = document.getElementById(boxId);
    if (!box) return;
    var vid = box.querySelector('video');
    if (!vid) return;
    box.addEventListener('click', function () {
        if (vid.paused) {
            var p = vid.play();
            if (p !== undefined) {
                p.catch(function () {});
            }
            box.classList.add('is-playing');
        } else {
            vid.pause();
            box.classList.remove('is-playing');
        }
    });
    vid.addEventListener('ended', function () {
        box.classList.remove('is-playing');
    });
}

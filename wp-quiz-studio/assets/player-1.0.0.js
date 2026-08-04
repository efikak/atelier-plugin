/* Quiz Atelier 1.0.0 public question engine. */
(() => {
  const config = window.WPQS_PLAYER || {};
  const apiBase = config.api || `${location.origin}/wp-json/wp-quiz-studio/v1/`;
  const labels = {
    quiz: 'Quiz', start: 'Έναρξη quiz', question: 'Ερώτηση', of: 'από', hint: 'Βοήθεια',
    continue: 'Συνέχεια', skip: 'Παράλειψη', chooseOne: 'Επιλέξτε μία απάντηση.',
    chooseAtLeastOne: 'Επιλέξτε τουλάχιστον μία απάντηση.', enterAnswer: 'Γράψτε την απάντησή σας.',
    answerPlaceholder: 'Πληκτρολογήστε την απάντησή σας', enterNumber: 'Γράψτε έναν αριθμό.',
    completeMatching: 'Ολοκληρώστε όλες τις αντιστοιχίσεις.', noAnswers: 'Δεν υπάρχουν διαθέσιμες απαντήσεις.',
    correct: 'Σωστά!', wrong: 'Λάθος απάντηση', skipped: 'Η ερώτηση παραλείφθηκε',
    pollRecorded: 'Η απάντησή σας καταχωρήθηκε.', correctAnswer: 'Η σωστή απάντηση είναι:',
    correctAnswers: 'Οι σωστές απαντήσεις είναι:', explanation: 'Επεξήγηση', timeUp: 'Ο χρόνος τελείωσε.',
    calculating: 'Υπολογισμός αποτελέσματος…', completed: 'Ολοκληρώθηκε',
    defaultResult: 'Απαντήσατε σωστά σε %1$d από %2$d ερωτήσεις.', score: 'Βαθμολογία',
    passed: 'Επιτυχία', failed: 'Δεν επιτεύχθηκε η βάση', personalityMatch: 'Ταίριασμα',
    share: 'Κοινοποίηση', copyLink: 'Αντιγραφή συνδέσμου', linkCopied: 'Ο σύνδεσμος αντιγράφηκε.',
    restart: 'Παίξτε ξανά', reviewAnswers: 'Δείτε τις απαντήσεις σας', hideReview: 'Απόκρυψη απαντήσεων',
    unableSubmit: 'Δεν ήταν δυνατή η υποβολή', tryAgain: 'Δοκιμάστε ξανά αργότερα.',
    unavailable: 'Το quiz δεν είναι διαθέσιμο αυτή τη στιγμή.', expired: 'Το quiz έχει λήξει.',
    availableUntil: 'Διαθέσιμο έως %s', category: 'Κατηγορία', yourAnswer: 'Η απάντησή σας:',
    notAnswered: 'Δεν απαντήθηκε', correctOrder: 'Σωστή σειρά:', moveUp: 'Πάνω', moveDown: 'Κάτω',
    blockedTitle: 'Μη εγκεκριμένο site', blockedMessage: 'Ωχ! Αυτό το quiz μάλλον πήγε εκδρομή χωρίς άδεια. Το συγκεκριμένο domain δεν βρίσκεται στη λίστα των εγκεκριμένων sites.',
    ...(config.labels || {})
  };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[char]));
  const stripHtml = value => {
    const element = document.createElement('div');
    element.innerHTML = String(value || '');
    return element.textContent || '';
  };
  const format = (template, ...values) => values.reduce((result, value, index) => result
    .replace(`%${index + 1}$d`, String(value))
    .replace(`%${index + 1}$s`, String(value))
    .replace('%s', String(value)), String(template || ''));
  const number = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback;
  const sessionId = () => window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const host = value => {
    try { return new URL(value).hostname.toLowerCase().replace(/^www\./, ''); } catch (_) { return ''; }
  };
  const request = async (path, options = {}) => {
    const response = await fetch(apiBase + path, {headers: {'Content-Type': 'application/json'}, ...options});
    const raw = await response.text();
    let body = null;
    try { body = raw ? JSON.parse(raw) : null; } catch (_) {
      const error = new Error(`Μη έγκυρη απάντηση διακομιστή (${response.status}).`);
      error.status = response.status;
      throw error;
    }
    if (!response.ok) {
      const error = new Error(body?.message || `Το αίτημα απέτυχε (${response.status}).`);
      error.status = response.status;
      error.data = body;
      throw error;
    }
    return body;
  };
  const normaliseQuiz = raw => {
    const quiz = raw && typeof raw === 'object' ? raw : {};
    quiz.title = String(quiz.title || labels.quiz);
    quiz.description = String(quiz.description || '');
    quiz.settings = quiz.settings && typeof quiz.settings === 'object' ? quiz.settings : {};
    quiz.settings.intro = quiz.settings.intro && typeof quiz.settings.intro === 'object' ? quiz.settings.intro : {};
    quiz.theme = quiz.theme && typeof quiz.theme === 'object' ? quiz.theme : {};
    quiz.category = quiz.category && typeof quiz.category === 'object' ? quiz.category : null;
    quiz.runtime_excluded_questions = Array.isArray(quiz.runtime_excluded_questions) ? quiz.runtime_excluded_questions.map(Number) : [];
    quiz.questions = Array.isArray(quiz.questions) ? quiz.questions.map(question => ({
      ...question,
      content: question?.content || {},
      settings: question?.settings || {},
      matching_options: Array.isArray(question?.matching_options) ? question.matching_options : [],
      answers: Array.isArray(question?.answers) ? question.answers.map(answer => ({...answer, content: answer?.content || {}})) : []
    })) : [];
    return quiz;
  };
  const publicContext = () => {
    const params = new URLSearchParams(location.search);
    const width = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    let timezone = '';
    try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (_) {}
    return {
      referrer: document.referrer || '',
      device: width < 768 ? 'mobile' : width < 1024 ? 'tablet' : 'desktop',
      language: navigator.language || '',
      timezone,
      screen: `${window.screen?.width || width}x${window.screen?.height || 0}`,
      utm_source: params.get('utm_source') || '',
      utm_medium: params.get('utm_medium') || '',
      utm_campaign: params.get('utm_campaign') || ''
    };
  };
  const mediaMarkup = content => {
    if (content?.image_url) return `<img class="wpqs-question-media" src="${esc(content.image_url)}" alt="" loading="lazy">`;
    if (content?.video_url) return `<video class="wpqs-question-media" src="${esc(content.video_url)}" controls playsinline></video>`;
    if (content?.audio_url) return `<audio class="wpqs-question-media" src="${esc(content.audio_url)}" controls></audio>`;
    return '';
  };
  const themeStyle = quiz => {
    const theme = quiz.theme || {};
    const font = theme.font === 'serif' ? 'Georgia,serif' : theme.font === 'rounded' ? 'ui-rounded,system-ui,sans-serif' : 'system-ui,sans-serif';
    const shadow = theme.shadow === 'none' ? 'none' : theme.shadow === 'strong' ? '0 24px 70px rgba(2,6,23,.28)' : '0 12px 42px rgba(15,23,42,.13)';
    return [
      `--wpqs-primary:${esc(theme.primary || '#4f46e5')}`, `--wpqs-secondary:${esc(theme.secondary || '#7c3aed')}`,
      `--wpqs-page:${esc(theme.page || '#f4f4f8')}`, `--wpqs-background:${esc(theme.background || '#ffffff')}`,
      `--wpqs-text:${esc(theme.text || '#111827')}`, `--wpqs-muted:${esc(theme.muted || '#4b5563')}`,
      `--wpqs-button:${esc(theme.button || theme.primary || '#4f46e5')}`, `--wpqs-button-text:${esc(theme.button_text || '#ffffff')}`,
      `--wpqs-answer:${esc(theme.answer || '#f8fafc')}`, `--wpqs-border:${esc(theme.border || '#cbd5e1')}`,
      `--wpqs-correct:${esc(theme.correct || '#15803d')}`, `--wpqs-wrong:${esc(theme.wrong || '#b91c1c')}`,
      `--wpqs-radius:${number(theme.radius, 16)}px`, `--wpqs-font:${font}`, `--wpqs-shadow:${shadow}`
    ].join(';');
  };
  const dateLabel = value => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
    if (Number.isNaN(date.getTime())) return '';
    try { return new Intl.DateTimeFormat(config.locale || 'el-GR', {dateStyle: 'medium', timeStyle: 'short'}).format(date); }
    catch (_) { return date.toLocaleString(); }
  };

  const guardAllows = () => {
    const guard = window.WPQS_EMBED_GUARD;
    if (!guard?.restricted || window.self === window.top) return true;
    const parentHost = host(document.referrer);
    if (!parentHost) return false;
    return (guard.domains || []).some(raw => {
      const allowed = String(raw || '').toLowerCase().replace(/^www\./, '').replace(/^\*\./, '');
      return parentHost === allowed || (guard.allowSubdomains && parentHost.endsWith(`.${allowed}`));
    });
  };
  const blockedMarkup = () => {
    const guard = window.WPQS_EMBED_GUARD || {};
    return `<main class="wpqs-embed-denied"><div><span aria-hidden="true">🛂</span><h1>${esc(guard.title || labels.blockedTitle)}</h1><p>${esc(guard.message || labels.blockedMessage)}</p></div></main>`;
  };
  if (!guardAllows()) {
    document.querySelectorAll('.wpqs-player').forEach(mount => { mount.innerHTML = blockedMarkup(); });
    return;
  }

  document.querySelectorAll('.wpqs-player').forEach(async mount => {
    const id = Number(mount.dataset.quiz || 0);
    if (!id) return;

    try {
      const quiz = normaliseQuiz(await request(`public/quizzes/${id}`));
      let session = sessionId();
      let responses = {};
      let timings = {};
      let hiddenQuestions = new Set(quiz.runtime_excluded_questions);
      const context = publicContext();
      let step = -1;
      let questionStarted = 0;
      let timerHandle = null;
      let actionLocked = false;

      const track = (event, metadata = {}, questionId = 0) => request(`public/quizzes/${id}/events`, {
        method: 'POST', body: JSON.stringify({event, question_id: questionId || undefined, session_id: session, metadata: {...context, ...metadata}})
      }).catch(() => {});

      const hasResponse = value => !(value === undefined || value === null || value === '' || value === false || (Array.isArray(value) && !value.length) || (typeof value === 'object' && !Array.isArray(value) && !Object.keys(value).length));
      const ruleSatisfied = rule => {
        const source = quiz.questions.find(item => String(item?.settings?.key || '') === String(rule?.question_key || ''));
        if (!source) return false;
        const response = responses[String(source.id)];
        const answered = hasResponse(response);
        if (rule?.operator === 'answered') return answered;
        if (rule?.operator === 'not_answered') return !answered;
        if (!answered) return false;
        const target = source.answers.find(answer => String(answer?.content?.key || '') === String(rule?.answer_key || ''));
        if (!target) return false;
        const selected = Array.isArray(response) ? response.map(Number) : [Number(response)];
        const matches = selected.includes(Number(target.id));
        return rule?.operator === 'not_equals' ? !matches : matches;
      };
      const conditionSatisfied = question => {
        const condition = question?.settings?.condition || {};
        if (!condition.enabled) return true;
        const rules = Array.isArray(condition.rules) && condition.rules.length ? condition.rules : [{
          operator: condition.operator || 'equals', question_key: condition.question_key || '', answer_key: condition.answer_key || ''
        }];
        const results = rules.map(ruleSatisfied);
        return condition.match === 'any' ? results.some(Boolean) : results.every(Boolean);
      };
      const clearTimer = () => { if (timerHandle) window.clearInterval(timerHandle); timerHandle = null; };
      const recordTime = question => {
        if (!questionStarted || !question?.id) return;
        timings[String(question.id)] = Math.max(0, Math.round((Date.now() - questionStarted) / 100) / 10);
        questionStarted = 0;
      };
      const moveNext = () => { actionLocked = false; step += 1; draw(); };
      const optionLabel = (question, optionId) => {
        const item = question.answers.find(answer => Number(answer.id) === Number(optionId));
        return item ? `${item.content?.emoji || ''} ${item.content?.text || ''}`.trim() : '';
      };
      const organizationBrandMarkup = placement => {
        const brand = quiz.organization_branding || {};
        if (!brand || (!brand.logo_url && !brand.footer_text)) return '';
        if (placement === 'header' && brand.logo_url) {
          return `<div class="wpqs-org-brand"><img class="wpqs-org-logo" src="${esc(brand.logo_url)}" alt="${esc(brand.name || '')}" loading="lazy"></div>`;
        }
        if (placement === 'footer' && brand.footer_text) {
          return `<footer class="wpqs-org-footer">${esc(brand.footer_text)}</footer>`;
        }
        return '';
      };

      const selectedAnswerMarkup = (question, response) => {
        if (!hasResponse(response)) return `<div class="wpqs-selected-answer"><strong>${esc(labels.yourAnswer)}</strong><span>${esc(labels.notAnswered)}</span></div>`;
        if (['open_text', 'slider', 'numeric', 'rating'].includes(question.type)) {
          return `<div class="wpqs-selected-answer"><strong>${esc(labels.yourAnswer)}</strong><span>${esc(response)}</span></div>`;
        }
        if (['ordering', 'ranking'].includes(question.type)) {
          const items = (Array.isArray(response) ? response : []).map(value => optionLabel(question, value)).filter(Boolean);
          return `<div class="wpqs-selected-answer"><strong>${esc(labels.yourAnswer)}</strong><ol>${items.map(item => `<li>${esc(item)}</li>`).join('')}</ol></div>`;
        }
        if (question.type === 'matching') {
          const rows = question.answers.map(answer => {
            const selectedId = response?.[String(answer.id)] || response?.[answer.id];
            const option = question.matching_options.find(item => Number(item.id) === Number(selectedId));
            return `<span>${esc(answer.content?.text || '')} → ${esc(option?.text || labels.notAnswered)}</span>`;
          });
          return `<div class="wpqs-selected-answer"><strong>${esc(labels.yourAnswer)}</strong>${rows.join('')}</div>`;
        }
        const ids = Array.isArray(response) ? response.map(Number) : [Number(response)];
        const selected = question.answers.filter(answer => ids.includes(Number(answer.id)));
        return `<div class="wpqs-selected-answer"><strong>${esc(labels.yourAnswer)}</strong>${selected.map(answer => `<span>${esc(answer.content?.emoji || '')} ${esc(answer.content?.text || '')}</span>`).join('')}</div>`;
      };
      const correctAnswersMarkup = feedback => {
        const correct = Array.isArray(feedback?.correct_answers) ? feedback.correct_answers : [];
        if (!correct.length) return '';
        const heading = correct.length > 1 ? labels.correctAnswers : labels.correctAnswer;
        return `<section class="wpqs-correct-answer"><strong>${esc(heading)}</strong><div>${correct.map(answer => `<span>${answer.image_url ? `<img src="${esc(answer.image_url)}" alt="" loading="lazy">` : ''}${esc(answer.emoji || '')} ${esc(answer.text || '')}</span>`).join('')}</div></section>`;
      };
      const renderFeedback = (question, response, feedback, timedOut = false) => {
        const isPoll = feedback?.gradable === false;
        const correct = feedback?.correct === true;
        const skipped = feedback?.skipped === true;
        const stateClass = isPoll ? 'poll' : correct ? 'correct' : 'wrong';
        const icon = isPoll ? '✓' : correct ? '✓' : '×';
        const heading = timedOut ? labels.timeUp : isPoll ? labels.pollRecorded : skipped ? labels.skipped : correct ? labels.correct : labels.wrong;
        mount.innerHTML = `<section class="wpqs-game wpqs-feedback ${stateClass}" style="${themeStyle(quiz)}">
          <span class="wpqs-feedback-icon" aria-hidden="true">${icon}</span><h2>${esc(heading)}</h2>
          ${selectedAnswerMarkup(question, response)}
          ${!correct && !isPoll ? correctAnswersMarkup(feedback) : ''}
          ${feedback?.explanation ? `<section class="wpqs-explanation"><strong>${esc(labels.explanation)}</strong><div>${feedback.explanation}</div></section>` : ''}
          <button class="wpqs-main-button" type="button">${esc(labels.continue)}</button>
        </section>`;
        mount.querySelector('button').onclick = moveNext;
        mount.querySelector('button').focus();
      };

      const emptyResponse = type => type === 'multiple_answers' || ['ordering', 'ranking'].includes(type) ? [] : type === 'matching' ? {} : '';
      const submitQuestion = async (question, response, timedOut = false) => {
        if (actionLocked) return;
        actionLocked = true;
        clearTimer();
        recordTime(question);
        responses[String(question.id)] = response;
        if (quiz.settings.show_feedback === false) { moveNext(); return; }
        mount.innerHTML = `<section class="wpqs-game wpqs-loading" style="${themeStyle(quiz)}"><span class="wpqs-spinner" aria-hidden="true"></span><p>${esc(labels.calculating)}</p></section>`;
        try {
          const feedback = await request(`public/quizzes/${id}/check`, {
            method: 'POST', body: JSON.stringify({session_id: session, question_id: Number(question.id), answer: response})
          });
          renderFeedback(question, response, feedback, timedOut);
        } catch (_) { moveNext(); }
      };

      const orderedMarkup = question => `<div class="wpqs-order-list" data-order-list>${question.answers.map((answer, index) => `<div class="wpqs-order-item" draggable="true" data-order-id="${answer.id}"><span class="wpqs-order-handle" aria-hidden="true">☰</span><span class="wpqs-order-number">${index + 1}</span><strong>${esc(answer.content?.text || '')}</strong><div><button type="button" data-order-move="-1" aria-label="${esc(labels.moveUp)}">↑</button><button type="button" data-order-move="1" aria-label="${esc(labels.moveDown)}">↓</button></div></div>`).join('')}</div><button class="wpqs-main-button wpqs-next" type="button">${esc(labels.continue)}</button>`;
      const matchingMarkup = question => `<div class="wpqs-matching-list">${question.answers.map(answer => `<label><span>${esc(answer.content?.text || '')}</span><select data-match-left="${answer.id}"><option value="">— Επιλέξτε —</option>${question.matching_options.map(option => `<option value="${option.id}">${esc(option.text)}</option>`).join('')}</select></label>`).join('')}</div><button class="wpqs-main-button wpqs-next" type="button">${esc(labels.continue)}</button>`;
      const draw = () => {
        clearTimer();
        actionLocked = false;
        const style = themeStyle(quiz);
        if (step < 0) {
          const intro = quiz.settings.intro || {};
          const expiry = dateLabel(quiz.expires_at);
          mount.innerHTML = `<section class="wpqs-game wpqs-intro" style="${style}">
            ${organizationBrandMarkup('header')}
            ${intro.image_url ? `<img class="wpqs-cover" src="${esc(intro.image_url)}" alt="" loading="lazy">` : ''}
            ${quiz.category?.name ? `<span class="wpqs-category-badge">${esc(quiz.category.name)}</span>` : ''}
            <h1>${esc(intro.title || quiz.title)}</h1>${intro.subtitle ? `<p class="wpqs-subtitle">${esc(intro.subtitle)}</p>` : ''}
            <p>${esc(stripHtml(quiz.description))}</p>${expiry ? `<p class="wpqs-expiry">${esc(format(labels.availableUntil, expiry))}</p>` : ''}
            <button class="wpqs-main-button" type="button">${esc(intro.button || labels.start)}</button>
            ${organizationBrandMarkup('footer')}
          </section>`;
          mount.querySelector('button').onclick = () => { step = 0; track('start'); draw(); };
          return;
        }

        while (step < quiz.questions.length && !conditionSatisfied(quiz.questions[step])) {
          const hidden = quiz.questions[step];
          if (hidden?.id) hiddenQuestions.add(Number(hidden.id));
          step += 1;
        }
        const question = quiz.questions[step];
        if (!question) { finish(); return; }
        if (question?.id) hiddenQuestions.delete(Number(question.id));
        questionStarted = Date.now();
        track('question_view', {}, Number(question.id));
        const progress = quiz.questions.length ? ((step + 1) / quiz.questions.length) * 100 : 0;
        const type = question.type || 'multiple_choice';
        const required = question.settings?.required !== false;
        let answerMarkup = '';

        if (type === 'multiple_answers') {
          answerMarkup = `<div class="wpqs-answer-list">${question.answers.map(answer => `<label class="wpqs-check-answer">${answer.content?.image_url ? `<img src="${esc(answer.content.image_url)}" alt="" loading="lazy">` : ''}<input type="checkbox" value="${answer.id}"><span>${esc(answer.content?.emoji || '')} ${esc(answer.content?.text)}</span></label>`).join('')}</div><button class="wpqs-main-button wpqs-next" type="button">${esc(labels.continue)}</button>`;
        } else if (type === 'open_text') {
          answerMarkup = `<textarea class="wpqs-open-answer" rows="4" placeholder="${esc(labels.answerPlaceholder)}"></textarea><button class="wpqs-main-button wpqs-next" type="button">${esc(labels.continue)}</button>`;
        } else if (type === 'slider') {
          const min = number(question.settings?.slider_min, 0), max = number(question.settings?.slider_max, 100), stepValue = Math.max(.0001, number(question.settings?.slider_step, 1));
          const value = min + ((max - min) / 2);
          answerMarkup = `<div class="wpqs-slider-wrap"><output data-slider-output>${esc(value)}</output><input class="wpqs-slider" type="range" min="${min}" max="${max}" step="${stepValue}" value="${value}"><div><span>${min}</span><span>${max}</span></div></div><button class="wpqs-main-button wpqs-next" type="button">${esc(labels.continue)}</button>`;
        } else if (type === 'numeric') {
          answerMarkup = `<input class="wpqs-number-answer" type="number" step="any" inputmode="decimal" placeholder="0"><button class="wpqs-main-button wpqs-next" type="button">${esc(labels.continue)}</button>`;
        } else if (type === 'rating') {
          const max = Math.max(2, Math.min(20, number(question.settings?.rating_max, 5)));
          const stars = question.settings?.rating_style !== 'numbers';
          answerMarkup = `<div class="wpqs-rating ${stars ? 'is-stars' : ''}" role="radiogroup">${Array.from({length: max}, (_, index) => `<button type="button" data-rating="${index + 1}" aria-label="${index + 1}">${stars ? '★' : index + 1}</button>`).join('')}</div><button class="wpqs-main-button wpqs-next" type="button">${esc(labels.continue)}</button>`;
        } else if (['ordering', 'ranking'].includes(type)) {
          answerMarkup = orderedMarkup(question);
        } else if (type === 'matching') {
          answerMarkup = matchingMarkup(question);
        } else {
          answerMarkup = question.answers.length
            ? `<div class="wpqs-answer-list">${question.answers.map(answer => `<button class="wpqs-answer ${type === 'image_choice' ? 'image-answer' : ''}" type="button" data-answer="${answer.id}">${answer.content?.image_url ? `<img src="${esc(answer.content.image_url)}" alt="" loading="lazy">` : ''}<span>${esc(answer.content?.emoji || '')} ${esc(answer.content?.text)}</span></button>`).join('')}</div>`
            : `<p>${esc(labels.noAnswers)}</p><button class="wpqs-skip" type="button">${esc(labels.continue)}</button>`;
        }

        mount.innerHTML = `<section class="wpqs-game" style="${style}">
          ${quiz.settings.show_progress !== false ? `<div class="wpqs-progress" aria-label="${esc(labels.question)} ${step + 1} ${esc(labels.of)} ${quiz.questions.length}"><i style="width:${progress}%"></i></div>` : ''}
          <div class="wpqs-meta"><small>${esc(labels.question.toUpperCase())} ${step + 1} / ${quiz.questions.length}</small>${number(question.settings?.timer) > 0 ? `<strong class="wpqs-timer" aria-live="polite">${number(question.settings.timer)}s</strong>` : ''}</div>
          ${mediaMarkup(question.content)}<h2>${esc(question.content?.title)}</h2>
          ${question.settings?.hint ? `<p class="wpqs-hint"><strong>${esc(labels.hint)}:</strong> ${esc(question.settings.hint)}</p>` : ''}
          ${answerMarkup}${!required ? `<button class="wpqs-skip" type="button">${esc(labels.skip)}</button>` : ''}
          <p class="wpqs-validation" aria-live="polite"></p>
        </section>`;

        mount.querySelectorAll('.wpqs-answer').forEach(button => button.onclick = () => {
          mount.querySelectorAll('.wpqs-answer').forEach(item => item.classList.remove('is-selected'));
          button.classList.add('is-selected');
          submitQuestion(question, Number(button.dataset.answer));
        });
        mount.querySelector('.wpqs-slider')?.addEventListener('input', event => { mount.querySelector('[data-slider-output]').value = event.target.value; });
        mount.querySelectorAll('[data-rating]').forEach(button => button.onclick = () => {
          mount.querySelectorAll('[data-rating]').forEach(item => item.classList.toggle('is-selected', number(item.dataset.rating) <= number(button.dataset.rating)));
          mount.querySelector('.wpqs-rating').dataset.value = button.dataset.rating;
        });
        mount.querySelectorAll('[data-order-move]').forEach(button => button.onclick = () => {
          const item = button.closest('.wpqs-order-item');
          const list = item?.parentElement;
          const direction = number(button.dataset.orderMove);
          if (!item || !list) return;
          if (direction < 0 && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
          if (direction > 0 && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
          [...list.children].forEach((row, index) => { const count = row.querySelector('.wpqs-order-number'); if (count) count.textContent = String(index + 1); });
        });
        let draggedOrderItem = null;
        mount.querySelectorAll('.wpqs-order-item[draggable="true"]').forEach(item => {
          item.addEventListener('dragstart', event => {
            draggedOrderItem = item;
            item.classList.add('is-dragging');
            event.dataTransfer?.setData('text/plain', String(item.dataset.orderId || ''));
            if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
          });
          item.addEventListener('dragover', event => {
            event.preventDefault();
            if (!draggedOrderItem || draggedOrderItem === item) return;
            const box = item.getBoundingClientRect();
            const before = event.clientY < box.top + box.height / 2;
            item.parentElement?.insertBefore(draggedOrderItem, before ? item : item.nextSibling);
          });
          item.addEventListener('dragend', () => {
            draggedOrderItem?.classList.remove('is-dragging');
            draggedOrderItem = null;
            mount.querySelectorAll('.wpqs-order-item').forEach((row, index) => {
              const count = row.querySelector('.wpqs-order-number');
              if (count) count.textContent = String(index + 1);
            });
          });
        });
        const syncMatchingOptions = () => {
          const selects = [...mount.querySelectorAll('[data-match-left]')];
          const selected = selects.map(select => select.value).filter(Boolean);
          selects.forEach(select => [...select.options].forEach(option => {
            if (!option.value) return;
            option.disabled = option.value !== select.value && selected.includes(option.value);
          }));
        };
        mount.querySelectorAll('[data-match-left]').forEach(select => select.addEventListener('change', syncMatchingOptions));
        syncMatchingOptions();
        mount.querySelector('.wpqs-next')?.addEventListener('click', () => {
          const validation = mount.querySelector('.wpqs-validation');
          let response = '';
          if (type === 'multiple_answers') {
            response = [...mount.querySelectorAll('input[type="checkbox"]:checked')].map(input => Number(input.value));
            if (required && !response.length) { validation.textContent = labels.chooseAtLeastOne; return; }
          } else if (type === 'open_text') {
            response = mount.querySelector('.wpqs-open-answer')?.value.trim() || '';
            if (required && !response) { validation.textContent = labels.enterAnswer; return; }
          } else if (type === 'slider') {
            response = number(mount.querySelector('.wpqs-slider')?.value);
          } else if (type === 'numeric') {
            const raw = mount.querySelector('.wpqs-number-answer')?.value ?? '';
            if (required && raw === '') { validation.textContent = labels.enterNumber; return; }
            response = raw === '' ? '' : number(raw);
          } else if (type === 'rating') {
            response = number(mount.querySelector('.wpqs-rating')?.dataset.value);
            if (required && !response) { validation.textContent = labels.chooseOne; return; }
          } else if (['ordering', 'ranking'].includes(type)) {
            response = [...mount.querySelectorAll('[data-order-id]')].map(item => number(item.dataset.orderId));
          } else if (type === 'matching') {
            response = {};
            mount.querySelectorAll('[data-match-left]').forEach(select => { if (select.value) response[String(select.dataset.matchLeft)] = number(select.value); });
            if (required && Object.keys(response).length !== question.answers.length) { validation.textContent = labels.completeMatching; return; }
          }
          submitQuestion(question, response);
        });
        mount.querySelector('.wpqs-skip')?.addEventListener('click', () => submitQuestion(question, emptyResponse(type)));

        const seconds = number(question.settings?.timer);
        if (seconds > 0) {
          let remaining = seconds;
          timerHandle = window.setInterval(() => {
            remaining -= 1;
            const timer = mount.querySelector('.wpqs-timer');
            if (timer) timer.textContent = `${Math.max(0, remaining)}s`;
            if (remaining <= 0) submitQuestion(question, emptyResponse(type), true);
          }, 1000);
        }
      };

      const reviewMarkup = rows => `<section class="wpqs-review" hidden data-review-panel><h2>${esc(labels.reviewAnswers)}</h2>${(rows || []).map((row, index) => `<article class="${row.correct === true ? 'is-correct' : row.correct === false ? 'is-wrong' : 'is-neutral'}"><header><span>${index + 1}</span><h3>${esc(row.question)}</h3><b>${row.correct === true ? '✓' : row.correct === false ? '×' : '•'}</b></header><div><strong>${esc(labels.yourAnswer)}</strong>${row.selected_answers?.length ? `<ul>${row.selected_answers.map(item => `<li>${esc(item)}</li>`).join('')}</ul>` : `<p>${esc(labels.notAnswered)}</p>`}${row.correct === false && row.correct_answers?.length ? `<strong>${esc(labels.correctAnswers)}</strong><ul>${row.correct_answers.map(item => `<li>${esc(item)}</li>`).join('')}</ul>` : ''}${row.explanation ? `<div class="wpqs-review-explanation"><strong>${esc(labels.explanation)}</strong><div>${row.explanation}</div></div>` : ''}</div></article>`).join('')}</section>`;
      const restart = () => {
        session = sessionId(); responses = {}; timings = {}; hiddenQuestions = new Set(quiz.runtime_excluded_questions);
        step = -1; questionStarted = 0; actionLocked = false; track('restart'); draw();
      };
      const finish = async () => {
        clearTimer();
        mount.innerHTML = `<section class="wpqs-game wpqs-loading" style="${themeStyle(quiz)}"><span class="wpqs-spinner" aria-hidden="true"></span><p>${esc(labels.calculating)}</p></section>`;
        try {
          const result = await request(`public/quizzes/${id}/submit`, {
            method: 'POST', body: JSON.stringify({session_id: session, answers: responses, timings, hidden_questions: [...hiddenQuestions]})
          });
          const mapped = result.result || {};
          const title = mapped.title || labels.completed;
          const description = mapped.description || format(labels.defaultResult, result.correct, result.total);
          const tieProfiles = Array.isArray(mapped.tie_profiles) && mapped.tie_profiles.length > 1 ? `<div class="wpqs-personality-ties">${mapped.tie_profiles.map(profile => `<span>${esc(profile.title)} ${number(profile.percentage)}%</span>`).join('')}</div>` : '';
          const personality = mapped.key ? `<p class="wpqs-personality-match">${esc(labels.personalityMatch)}: <strong>${number(mapped.percentage)}%</strong></p>${tieProfiles}` : '';
          const pass = result.pass === true ? `<span class="wpqs-pass is-pass">✓ ${esc(labels.passed)}</span>` : result.pass === false ? `<span class="wpqs-pass is-fail">× ${esc(labels.failed)}</span>` : '';
          const review = quiz.settings.review_answers !== false && Array.isArray(result.review) && result.review.length ? reviewMarkup(result.review) : '';
          mount.innerHTML = `<section class="wpqs-game wpqs-result" style="${themeStyle(quiz)}">
            ${organizationBrandMarkup('header')}
            ${mapped.image_url ? `<img class="wpqs-cover" src="${esc(mapped.image_url)}" alt="" loading="lazy">` : ''}
            ${quiz.category?.name ? `<span class="wpqs-category-badge">${esc(quiz.category.name)}</span>` : ''}
            ${pass}<h1>${esc(title)}</h1><p>${esc(stripHtml(description))}</p>${personality}
            ${number(result.max_score) > 0 ? `<p class="wpqs-score">${esc(labels.score)}: ${esc(result.score)} / ${esc(result.max_score)}</p>` : ''}
            ${mapped.cta_label && mapped.cta_url ? `<a class="wpqs-main-button" href="${esc(mapped.cta_url)}">${esc(mapped.cta_label)}</a>` : ''}
            <div class="wpqs-result-actions">${review ? `<button class="wpqs-review-toggle" type="button">${esc(labels.reviewAnswers)}</button>` : ''}${quiz.settings.allow_restart !== false ? `<button class="wpqs-main-button wpqs-restart" type="button">${esc(labels.restart)}</button>` : ''}<div class="wpqs-share"><button type="button" data-share>${esc(labels.share)}</button><button type="button" data-copy>${esc(labels.copyLink)}</button></div></div>
            <p class="wpqs-share-status" aria-live="polite"></p>${review}
            ${organizationBrandMarkup('footer')}
          </section>`;
          mount.querySelector('.wpqs-restart')?.addEventListener('click', restart);
          mount.querySelector('.wpqs-review-toggle')?.addEventListener('click', event => {
            const panel = mount.querySelector('[data-review-panel]');
            panel.hidden = !panel.hidden;
            event.currentTarget.textContent = panel.hidden ? labels.reviewAnswers : labels.hideReview;
          });
          mount.querySelector('[data-share]')?.addEventListener('click', async () => {
            track('share', {method: navigator.share ? 'native' : 'copy'});
            const shareData = {title: quiz.title, text: title, url: location.href};
            if (navigator.share) { try { await navigator.share(shareData); } catch (_) {} }
            else { await navigator.clipboard?.writeText(location.href); mount.querySelector('.wpqs-share-status').textContent = labels.linkCopied; }
          });
          mount.querySelector('[data-copy]')?.addEventListener('click', async () => {
            track('share', {method: 'copy'});
            await navigator.clipboard?.writeText(location.href);
            mount.querySelector('.wpqs-share-status').textContent = labels.linkCopied;
          });
        } catch (error) {
          mount.innerHTML = `<section class="wpqs-game" style="${themeStyle(quiz)}"><h1>${esc(error?.status === 410 ? labels.expired : labels.unableSubmit)}</h1><p>${esc(error?.message || labels.tryAgain)}</p></section>`;
        }
      };

      track('view');
      draw();
    } catch (error) {
      mount.innerHTML = `<div class="wpqs-game wpqs-unavailable"><h2>${esc(error?.status === 410 || error?.data?.expired ? labels.expired : labels.unavailable)}</h2>${error?.message ? `<p>${esc(error.message)}</p>` : ''}</div>`;
    }
  });
})();

/* Quiz Atelier 1.0.0 — release stability, conflict-safe editing and system diagnostics. */
(() => {
  const root = document.querySelector('#wpqs-app');
  if (!root || !window.WPQS) return;
  root.dataset.wpqsStudioBuild = '1.0.0';

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[char]));
  const clone = value => JSON.parse(JSON.stringify(value));
  const shuffled = values => { const copy = [...values]; for (let i = copy.length - 1; i > 0; i -= 1) { const j = Math.floor(Math.random() * (i + 1)); [copy[i], copy[j]] = [copy[j], copy[i]]; } return copy; };
  const number = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback;
  const bool = value => value === true || value === 1 || value === '1' || value === 'true';
  const makeKey = prefix => `${prefix}_${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36).slice(-4)}`;
  const quizTypes = ['knowledge', 'poll', 'personality', 'survey'];
  const questionTypes = ['multiple_choice', 'multiple_answers', 'true_false', 'image_choice', 'poll', 'open_text', 'slider', 'numeric', 'rating', 'ordering', 'ranking', 'matching'];
  const singleCorrectTypes = ['multiple_choice', 'true_false', 'image_choice'];
  const typeLabel = type => ({
    multiple_choice: 'Μία επιλογή',
    multiple_answers: 'Πολλαπλές επιλογές',
    true_false: 'Σωστό / Λάθος',
    image_choice: 'Επιλογή εικόνας',
    poll: 'Δημοσκόπηση',
    open_text: 'Ανοιχτό κείμενο',
    slider: 'Κλίμακα',
    numeric: 'Αριθμητική απάντηση',
    rating: 'Αξιολόγηση',
    ordering: 'Ταξινόμηση σειράς',
    ranking: 'Κατάταξη',
    matching: 'Αντιστοίχιση'
  }[type] || type);
  const quizTypeLabel = type => ({
    knowledge: 'Quiz γνώσεων', poll: 'Δημοσκόπηση', personality: 'Τεστ προσωπικότητας', survey: 'Έρευνα'
  }[type] || 'Quiz γνώσεων');
  const statusLabel = status => ({
    draft: 'Πρόχειρο', published: 'Δημοσιευμένο', scheduled: 'Προγραμματισμένο',
    private: 'Ιδιωτικό', expired: 'Έληξε'
  }[status] || status);
  const visibilityLabel = value => ({personal:'Private',organization:'Organization',universal:'Universal'}[value] || value);
  const workflowLabel = value => ({draft:'Draft',submitted:'Submitted for Review',changes_requested:'Changes Requested',approved:'Approved',published:'Published',archived:'Archived'}[value] || value);
  const orgRoleLabel = value => ({creator_admin:'Creator Admin',creator:'Quiz Creator',viewer:'Viewer'}[value] || value);

  const categoryIcons = {
    folder: '◫', news: '▤', sports: '◆', culture: '✦', fun: '☺', education: '⌁',
    personality: '◉', poll: '▥', star: '★'
  };
  const categoryIconLabel = icon => ({
    folder: 'Φάκελος', news: 'Ειδήσεις', sports: 'Αθλητικά', culture: 'Πολιτισμός', fun: 'Ψυχαγωγία',
    education: 'Εκπαίδευση', personality: 'Προσωπικότητα', poll: 'Δημοσκόπηση', star: 'Προτεινόμενα'
  }[icon] || 'Φάκελος');

  const quizThemePresets = {
    atelier: {label:'Atelier',description:'Μαύρο, χρυσό και λιλά με editorial χαρακτήρα.',preset:'atelier',primary:'#d9bd85',secondary:'#b9a7ff',page:'#08080a',background:'#15151b',text:'#f6f4ef',muted:'#b8b5be',button:'#d9bd85',button_text:'#111111',answer:'#202027',border:'#4a4852',correct:'#91d7b4',wrong:'#ff8b8b',radius:22,font:'serif',shadow:'strong'},
    classic: {label:'Κλασικό',description:'Καθαρό και ουδέτερο για κάθε είδος quiz.',preset:'classic',primary:'#4f46e5',secondary:'#7c3aed',page:'#f4f4f8',background:'#ffffff',text:'#111827',muted:'#4b5563',button:'#4f46e5',button_text:'#ffffff',answer:'#f8fafc',border:'#cbd5e1',correct:'#15803d',wrong:'#b91c1c',radius:16,font:'system',shadow:'soft'},
    contrast: {label:'Υψηλή αντίθεση',description:'Μέγιστη αναγνωσιμότητα και WCAG AA αντίθεση.',preset:'contrast',primary:'#000000',secondary:'#404040',page:'#ffffff',background:'#ffffff',text:'#000000',muted:'#262626',button:'#000000',button_text:'#ffffff',answer:'#ffffff',border:'#000000',correct:'#006b2d',wrong:'#a30000',radius:4,font:'system',shadow:'none'},
    news: {label:'Ειδήσεις',description:'Editorial κόκκινο για ειδησεογραφικά quiz.',preset:'news',primary:'#b91c1c',secondary:'#7f1d1d',page:'#f8fafc',background:'#ffffff',text:'#111827',muted:'#374151',button:'#b91c1c',button_text:'#ffffff',answer:'#fff7f7',border:'#d1d5db',correct:'#166534',wrong:'#991b1b',radius:5,font:'system',shadow:'soft'},
    dark: {label:'Σκούρο',description:'Σύγχρονο dark theme με μωβ accent.',preset:'dark',primary:'#c4b5fd',secondary:'#818cf8',page:'#09090b',background:'#18181b',text:'#fafafa',muted:'#d4d4d8',button:'#c4b5fd',button_text:'#18181b',answer:'#27272a',border:'#52525b',correct:'#4ade80',wrong:'#f87171',radius:18,font:'system',shadow:'strong'},
    midnight: {label:'Μεσονύκτιο',description:'Βαθύ μπλε με cyan και violet λεπτομέρειες.',preset:'midnight',primary:'#67e8f9',secondary:'#a78bfa',page:'#020617',background:'#0f172a',text:'#f8fafc',muted:'#cbd5e1',button:'#67e8f9',button_text:'#082f49',answer:'#1e293b',border:'#475569',correct:'#4ade80',wrong:'#fb7185',radius:20,font:'system',shadow:'strong'},
    ocean: {label:'Ωκεανός',description:'Φωτεινό teal με καθαρή εταιρική εμφάνιση.',preset:'ocean',primary:'#0f766e',secondary:'#0369a1',page:'#ecfeff',background:'#ffffff',text:'#0f172a',muted:'#334155',button:'#0f766e',button_text:'#ffffff',answer:'#f0fdfa',border:'#99f6e4',correct:'#166534',wrong:'#b91c1c',radius:18,font:'rounded',shadow:'soft'},
    forest: {label:'Δάσος',description:'Πράσινο, φυσικό και ήρεμο style.',preset:'forest',primary:'#166534',secondary:'#4d7c0f',page:'#f0fdf4',background:'#ffffff',text:'#14261a',muted:'#36543f',button:'#166534',button_text:'#ffffff',answer:'#f7fee7',border:'#bbf7d0',correct:'#166534',wrong:'#b91c1c',radius:14,font:'serif',shadow:'soft'},
    sunset: {label:'Ηλιοβασίλεμα',description:'Ζεστό πορτοκαλί και ροζ για lifestyle περιεχόμενο.',preset:'sunset',primary:'#c2410c',secondary:'#be123c',page:'#fff7ed',background:'#ffffff',text:'#1c1917',muted:'#57534e',button:'#c2410c',button_text:'#ffffff',answer:'#fff7ed',border:'#fed7aa',correct:'#166534',wrong:'#be123c',radius:20,font:'rounded',shadow:'soft'},
    sports: {label:'Αθλητικά',description:'Δυναμικό πράσινο και χρυσό.',preset:'sports',primary:'#065f46',secondary:'#d97706',page:'#ecfdf5',background:'#ffffff',text:'#10231d',muted:'#365a4d',button:'#065f46',button_text:'#ffffff',answer:'#f0fdf4',border:'#a7f3d0',correct:'#166534',wrong:'#b91c1c',radius:8,font:'system',shadow:'strong'},
    magazine: {label:'Περιοδικό',description:'Editorial serif εμφάνιση με διακριτική πολυτέλεια.',preset:'magazine',primary:'#7c2d12',secondary:'#a16207',page:'#fffbeb',background:'#ffffff',text:'#1c1917',muted:'#57534e',button:'#7c2d12',button_text:'#ffffff',answer:'#fffbeb',border:'#d6d3d1',correct:'#166534',wrong:'#9f1239',radius:2,font:'serif',shadow:'soft'},
    corporate: {label:'Εταιρικό',description:'Μπλε και teal για business περιβάλλον.',preset:'corporate',primary:'#1d4ed8',secondary:'#0f766e',page:'#f1f5f9',background:'#ffffff',text:'#0f172a',muted:'#475569',button:'#1d4ed8',button_text:'#ffffff',answer:'#f8fafc',border:'#cbd5e1',correct:'#15803d',wrong:'#b91c1c',radius:10,font:'system',shadow:'soft'},
    minimal: {label:'Μίνιμαλ',description:'Ασπρόμαυρο, λιτό και χωρίς περιττά στοιχεία.',preset:'minimal',primary:'#111827',secondary:'#6b7280',page:'#ffffff',background:'#ffffff',text:'#111827',muted:'#4b5563',button:'#111827',button_text:'#ffffff',answer:'#ffffff',border:'#d1d5db',correct:'#166534',wrong:'#b91c1c',radius:0,font:'serif',shadow:'none'}
  };

  const studioStylePresets = {
    atelier: {mode:'dark',preset:'atelier',accent:'#d9bd85',accent_light:'#f2dfb8',accent_text:'#111111',lilac:'#b9a7ff',page:'#08080a',surface:'#15151b',surface_raised:'#1b1b22',text:'#f6f4ef',muted:'#b8b5be',border:'#34343d',radius:18,density:'comfortable'},
    midnight: {mode:'dark',preset:'midnight',accent:'#67e8f9',accent_light:'#cffafe',accent_text:'#082f49',lilac:'#a78bfa',page:'#020617',surface:'#0f172a',surface_raised:'#172554',text:'#f8fafc',muted:'#cbd5e1',border:'#334155',radius:18,density:'comfortable'},
    graphite: {mode:'dark',preset:'graphite',accent:'#e5e7eb',accent_light:'#ffffff',accent_text:'#111111',lilac:'#9ca3af',page:'#09090b',surface:'#18181b',surface_raised:'#27272a',text:'#fafafa',muted:'#d4d4d8',border:'#3f3f46',radius:14,density:'compact'},
    contrast: {mode:'dark',preset:'contrast',accent:'#ffffff',accent_light:'#ffffff',accent_text:'#000000',lilac:'#ffffff',page:'#000000',surface:'#000000',surface_raised:'#111111',text:'#ffffff',muted:'#f5f5f5',border:'#ffffff',radius:6,density:'spacious'}
  };

  const normaliseUserPreferences = raw => {
    const merged = {...studioStylePresets.atelier, ...(raw && typeof raw === 'object' ? raw : {})};
    merged.mode = 'dark';
    if (merged.preset === 'light') merged.preset = 'atelier';
    return merged;
  };
  /**
   * Applies account preferences only inside Quiz Atelier. Earlier builds wrote
   * these variables on <html>, which unintentionally overrode the active theme.
   */
  const preferenceSurface = () => root.closest('.wpqs-front-studio, .wpqs-admin-wrap') || root;
  const applyUserPreferences = preferences => {
    const value = normaliseUserPreferences(preferences);
    const surface = preferenceSurface();
    const mode = 'dark';
    const palette = value;
    const style = surface.style;
    style.setProperty('--wpqs-ink', palette.text);
    style.setProperty('--wpqs-muted', palette.muted);
    style.setProperty('--wpqs-accent', palette.accent);
    style.setProperty('--wpqs-accent-light', palette.accent_light);
    style.setProperty('--wpqs-accent-ink', palette.accent_text || '#111111');
    style.setProperty('--wpqs-lilac', palette.lilac);
    style.setProperty('--wpqs-page', palette.page);
    style.setProperty('--wpqs-surface', palette.surface);
    style.setProperty('--wpqs-surface-soft', palette.surface);
    style.setProperty('--wpqs-surface-raised', palette.surface_raised);
    style.setProperty('--wpqs-border-solid', palette.border);
    style.setProperty('--wpqs-ui-radius', `${number(palette.radius, 18)}px`);
    style.setProperty('--wpqs-scrollbar', palette.accent);
    surface.dataset.wpqsDensity = palette.density || 'comfortable';
    surface.dataset.wpqsMode = mode;
    return {...value, mode};
  };

  const newAnswer = (text = 'Νέα απάντηση', correct = false, score = 0) => ({
    content: {key: makeKey('a'), text, match_text: '', image_id: 0, image_url: '', emoji: '', icon: '', personality_weights: {}},
    is_correct: correct,
    score
  });

  const newQuestion = () => ({
    type: 'multiple_choice',
    content: {title: 'Νέα ερώτηση', image_id: 0, image_url: '', video_url: '', audio_url: ''},
    settings: {
      key: makeKey('q'), hint: '', explanation: '', timer: 0, required: true,
      shuffle_answers: false, points: 1, slider_min: 0, slider_max: 100, slider_step: 1,
      correct_min: 0, correct_max: 100, numeric_answer: 0, numeric_tolerance: 0, rating_max: 5,
      multiple_scoring: 'exact', order_scoring: 'exact', matching_scoring: 'exact',
      text_case_sensitive: false, text_ignore_accents: true, text_ignore_punctuation: true, rating_style: 'stars',
      condition: {enabled: false, match: 'all', rules: [], operator: 'equals', question_key: '', answer_key: ''}
    },
    answers: [newAnswer('Απάντηση 1', true, 1), newAnswer('Απάντηση 2', false, 0)]
  });

  const cloneQuestionTemplate = source => {
    const question = clone(source);
    delete question.id;
    delete question.quiz_id;
    delete question.position;
    question.settings = question.settings || {};
    question.settings.key = makeKey('q');
    question.settings.condition = {enabled: false, match: 'all', rules: [], operator: 'equals', question_key: '', answer_key: ''};
    question.answers = Array.isArray(question.answers) ? question.answers.map(answer => {
      delete answer.id;
      delete answer.question_id;
      delete answer.position;
      answer.content = answer.content || {};
      answer.content.key = makeKey('a');
      return answer;
    }) : [];
    return question;
  };

  const normaliseQuestionCorrectness = question => {
    if (!question || !Array.isArray(question.answers)) return question;
    if (question.type === 'poll') {
      question.answers.forEach(answer => { answer.is_correct = false; answer.score = 0; });
      return question;
    }
    if (singleCorrectTypes.includes(question.type)) {
      let selected = -1;
      question.answers.forEach((answer, index) => { if (bool(answer.is_correct)) selected = index; });
      question.answers.forEach((answer, index) => {
        answer.is_correct = selected >= 0 && index === selected;
        if (answer.is_correct && number(answer.score) <= 0) answer.score = Math.max(1, number(question.settings?.points, 1));
      });
    }
    if (question.type === 'multiple_answers' || question.type === 'open_text') {
      question.answers.forEach(answer => {
        if (bool(answer.is_correct) && number(answer.score) <= 0) answer.score = 1;
      });
    }
    return question;
  };

  const normaliseQuizCorrectness = quiz => {
    if (quiz && Array.isArray(quiz.questions)) quiz.questions.forEach(normaliseQuestionCorrectness);
    return quiz;
  };

  /** Reconfigures only the fields required by a question type without submitting the page. */
  const configureQuestionType = (question, nextType) => {
    const previousType = question.type;
    question.type = questionTypes.includes(nextType) ? nextType : 'multiple_choice';
    question.answers = Array.isArray(question.answers) ? question.answers : [];

    const ensureAnswers = (minimum = 2) => {
      while (question.answers.length < minimum) question.answers.push(newAnswer(`Απάντηση ${question.answers.length + 1}`, false, 0));
    };

    if (question.type === 'true_false') {
      if (previousType !== 'true_false') {
        question.answers = [newAnswer('Σωστό', true, 1), newAnswer('Λάθος', false, 0)];
      } else {
        ensureAnswers(2);
        question.answers = question.answers.slice(0, 2);
        question.answers[0].content.text = 'Σωστό';
        question.answers[1].content.text = 'Λάθος';
      }
    } else if (question.type === 'open_text') {
      ensureAnswers(1);
      question.answers.forEach((answer, index) => {
        answer.is_correct = index === 0 || bool(answer.is_correct);
        if (answer.is_correct && number(answer.score) === 0) answer.score = 1;
      });
    } else if (['multiple_choice','image_choice'].includes(question.type)) {
      ensureAnswers(2);
      if (!question.answers.some(answer => bool(answer.is_correct))) question.answers[0].is_correct = true;
    } else if (question.type === 'multiple_answers') {
      ensureAnswers(2);
      if (!question.answers.some(answer => bool(answer.is_correct))) question.answers[0].is_correct = true;
      question.answers.forEach(answer => { if (answer.is_correct && number(answer.score) <= 0) answer.score = 1; });
    } else if (question.type === 'poll') {
      ensureAnswers(2);
      question.answers.forEach(answer => { answer.is_correct = false; answer.score = 0; });
    } else if (['ordering','ranking','matching'].includes(question.type)) {
      ensureAnswers(2);
      question.answers.forEach((answer, index) => {
        answer.is_correct = false;
        if (question.type === 'matching' && !answer.content.match_text) answer.content.match_text = `Αντιστοίχιση ${index + 1}`;
      });
    } else if (['slider','numeric','rating'].includes(question.type)) {
      question.answers.forEach(answer => { answer.is_correct = false; });
      if (question.type === 'rating') {
        question.settings.rating_max = Math.max(2, number(question.settings.rating_max, 5));
        question.settings.slider_min = 1;
        question.settings.slider_max = question.settings.rating_max;
        question.settings.correct_min = Math.max(1, number(question.settings.correct_min, 1));
        question.settings.correct_max = Math.min(question.settings.rating_max, number(question.settings.correct_max, question.settings.rating_max));
      }
    }

    normaliseQuestionCorrectness(question);
    return question;
  };

  const api = async (path, options = {}) => {
    const response = await fetch(WPQS.api + path, {
      headers: {'Content-Type': 'application/json', 'X-WP-Nonce': WPQS.nonce},
      ...options
    });
    if (response.status === 204) return null;
    const raw = await response.text();
    let data = null;
    try { data = raw ? JSON.parse(raw) : null; } catch (_) {
      throw new Error(`Ο διακομιστής επέστρεψε μη έγκυρη απάντηση (${response.status}) για ${path}.`);
    }
    if (!response.ok) {
      const error = new Error(data?.message || `Το αίτημα απέτυχε (${response.status}).`);
      error.status = response.status;
      error.data = data;
      throw error;
    }
    return data;
  };

  const emptyQuiz = () => ({
    title: 'Νέο quiz', slug: '', quiz_type: 'knowledge', status: 'draft', workflow_status: 'draft', visibility_scope: 'personal',
    organization_id: number(WPQS.context?.organization_id), template_id: 0, review_comment: '', scheduled_at: null, expires_at: null,
    category_id: 0, category: null, description: '',
    settings: {
      intro: {title: 'Έτοιμοι να ξεκινήσουμε;', subtitle: '', button: 'Έναρξη quiz', image_id: 0, image_url: ''},
      category: '', show_progress: true, random_questions: false, random_question_limit: 0, show_feedback: true,
      show_correct_answer: true, allow_restart: true, review_answers: true, show_pass_fail: false, pass_score: 0,
      personality_profiles: [], personality_tie_strategy: 'first', results: [],
      embed_mode: 'inherit', embed_domains: [], embed_block_message: ''
    },
    theme: {
      preset: 'atelier', primary: '#d9bd85', secondary: '#b9a7ff', page: '#08080a',
      background: '#15151b', text: '#f6f4ef', muted: '#b8b5be', button: '#d9bd85',
      button_text: '#111111', answer: '#202027', border: '#4a4852',
      correct: '#91d7b4', wrong: '#ff8b8b', radius: 22, font: 'serif', shadow: 'strong'
    },
    questions: []
  });

  const normaliseQuiz = raw => {
    const defaults = emptyQuiz();
    const quiz = raw && typeof raw === 'object' ? raw : {};
    quiz.title = typeof quiz.title === 'string' ? quiz.title : defaults.title;
    quiz.slug = typeof quiz.slug === 'string' ? quiz.slug : '';
    quiz.description = typeof quiz.description === 'string' ? quiz.description : '';
    quiz.quiz_type = quizTypes.includes(quiz.quiz_type) ? quiz.quiz_type : 'knowledge';
    quiz.status = ['draft', 'published', 'scheduled', 'private', 'expired'].includes(quiz.status) ? quiz.status : 'draft';
    quiz.workflow_status = ['draft','submitted','changes_requested','approved','published','archived'].includes(quiz.workflow_status) ? quiz.workflow_status : (quiz.status === 'published' ? 'published' : 'draft');
    quiz.visibility_scope = ['personal','organization','universal'].includes(quiz.visibility_scope) ? quiz.visibility_scope : 'personal';
    quiz.organization_id = number(quiz.organization_id || WPQS.context?.organization_id);
    quiz.template_id = number(quiz.template_id);
    quiz.author_id = number(quiz.author_id);
    quiz.author_name = String(quiz.author_name || '');
    quiz.review_comment = String(quiz.review_comment || '');
    quiz.review_history = Array.isArray(quiz.review_history) ? quiz.review_history : [];
    quiz.scheduled_at = typeof quiz.scheduled_at === 'string' && quiz.scheduled_at ? quiz.scheduled_at : null;
    quiz.expires_at = typeof quiz.expires_at === 'string' && quiz.expires_at ? quiz.expires_at : null;
    quiz.category_id = number(quiz.category_id);
    quiz.category = quiz.category && typeof quiz.category === 'object' ? quiz.category : null;
    quiz.settings = quiz.settings && typeof quiz.settings === 'object' ? quiz.settings : {};
    quiz.settings.intro = quiz.settings.intro && typeof quiz.settings.intro === 'object' ? quiz.settings.intro : {};
    quiz.settings.intro = {...defaults.settings.intro, ...quiz.settings.intro};
    quiz.settings.category = typeof quiz.settings.category === 'string' ? quiz.settings.category : '';
    quiz.settings.show_progress = quiz.settings.show_progress === undefined ? true : bool(quiz.settings.show_progress);
    quiz.settings.random_questions = bool(quiz.settings.random_questions);
    quiz.settings.random_question_limit = number(quiz.settings.random_question_limit);
    quiz.settings.show_feedback = quiz.settings.show_feedback === undefined ? true : bool(quiz.settings.show_feedback);
    quiz.settings.show_correct_answer = quiz.settings.show_correct_answer === undefined ? true : bool(quiz.settings.show_correct_answer);
    quiz.settings.allow_restart = quiz.settings.allow_restart === undefined ? true : bool(quiz.settings.allow_restart);
    quiz.settings.review_answers = quiz.settings.review_answers === undefined ? true : bool(quiz.settings.review_answers);
    quiz.settings.show_pass_fail = bool(quiz.settings.show_pass_fail);
    quiz.settings.pass_score = number(quiz.settings.pass_score);
    quiz.settings.embed_mode = ['inherit','public','restricted'].includes(quiz.settings.embed_mode) ? quiz.settings.embed_mode : 'inherit';
    quiz.settings.embed_domains = Array.isArray(quiz.settings.embed_domains) ? quiz.settings.embed_domains.map(String) : typeof quiz.settings.embed_domains === 'string' ? quiz.settings.embed_domains.split(/[\r\n,;]+/).filter(Boolean) : [];
    quiz.settings.embed_block_message = String(quiz.settings.embed_block_message || '');
    quiz.settings.personality_tie_strategy = ['first','all'].includes(quiz.settings.personality_tie_strategy) ? quiz.settings.personality_tie_strategy : 'first';
    quiz.settings.personality_profiles = Array.isArray(quiz.settings.personality_profiles) ? quiz.settings.personality_profiles.map(profile => ({
      key: String(profile.key || makeKey('profile')), title: String(profile.title || 'Προσωπικότητα'), description: String(profile.description || ''),
      image_id: number(profile.image_id), image_url: String(profile.image_url || ''), cta_label: String(profile.cta_label || ''), cta_url: String(profile.cta_url || '')
    })) : [];
    quiz.settings.results = Array.isArray(quiz.settings.results) ? quiz.settings.results.map(range => ({
      min: number(range.min), max: number(range.max), title: String(range.title || 'Αποτέλεσμα'),
      description: String(range.description || ''), image_id: number(range.image_id), image_url: String(range.image_url || ''),
      cta_label: String(range.cta_label || ''), cta_url: String(range.cta_url || '')
    })) : [];
    quiz.theme = {...defaults.theme, ...(quiz.theme && typeof quiz.theme === 'object' ? quiz.theme : {})};
    quiz.questions = Array.isArray(quiz.questions) ? quiz.questions.map(rawQuestion => {
      const question = rawQuestion && typeof rawQuestion === 'object' ? rawQuestion : {};
      question.type = questionTypes.includes(question.type) ? question.type : 'multiple_choice';
      question.content = question.content && typeof question.content === 'object' ? question.content : {};
      question.content = {
        title: String(question.content.title || ''), image_id: number(question.content.image_id),
        image_url: String(question.content.image_url || ''), video_url: String(question.content.video_url || ''),
        audio_url: String(question.content.audio_url || '')
      };
      question.settings = question.settings && typeof question.settings === 'object' ? question.settings : {};
      const condition = question.settings.condition && typeof question.settings.condition === 'object' ? question.settings.condition : {};
      let rules = Array.isArray(condition.rules) ? condition.rules : [];
      if (!rules.length && condition.question_key) rules = [{operator: condition.operator || 'equals', question_key: condition.question_key, answer_key: condition.answer_key || ''}];
      rules = rules.map(rule => ({operator: ['equals','not_equals','answered','not_answered'].includes(rule.operator) ? rule.operator : 'equals', question_key: String(rule.question_key || ''), answer_key: String(rule.answer_key || '')}));
      question.settings = {
        key: String(question.settings.key || makeKey('q')),
        hint: String(question.settings.hint || ''), explanation: String(question.settings.explanation || ''),
        points: number(question.settings.points, 1), timer: number(question.settings.timer),
        shuffle_answers: bool(question.settings.shuffle_answers),
        required: question.settings.required === undefined ? true : bool(question.settings.required),
        slider_min: number(question.settings.slider_min), slider_max: number(question.settings.slider_max, 100), slider_step: number(question.settings.slider_step, 1),
        correct_min: number(question.settings.correct_min), correct_max: number(question.settings.correct_max, 100),
        numeric_answer: number(question.settings.numeric_answer), numeric_tolerance: number(question.settings.numeric_tolerance), rating_max: number(question.settings.rating_max, 5),
        multiple_scoring: ['exact','partial'].includes(question.settings.multiple_scoring) ? question.settings.multiple_scoring : 'exact',
        order_scoring: ['exact','partial'].includes(question.settings.order_scoring) ? question.settings.order_scoring : 'exact',
        matching_scoring: ['exact','partial'].includes(question.settings.matching_scoring) ? question.settings.matching_scoring : 'exact',
        text_case_sensitive: bool(question.settings.text_case_sensitive),
        text_ignore_accents: question.settings.text_ignore_accents === undefined ? true : bool(question.settings.text_ignore_accents),
        text_ignore_punctuation: question.settings.text_ignore_punctuation === undefined ? true : bool(question.settings.text_ignore_punctuation),
        rating_style: ['stars','numbers'].includes(question.settings.rating_style) ? question.settings.rating_style : 'stars',
        condition: {enabled: bool(condition.enabled), match: ['all','any'].includes(condition.match) ? condition.match : 'all', rules,
          operator: rules[0]?.operator || 'equals', question_key: rules[0]?.question_key || '', answer_key: rules[0]?.answer_key || ''}
      };
      question.answers = Array.isArray(question.answers) ? question.answers.map(rawAnswer => {
        const answer = rawAnswer && typeof rawAnswer === 'object' ? rawAnswer : {};
        answer.content = answer.content && typeof answer.content === 'object' ? answer.content : {};
        answer.content = {
          key: String(answer.content.key || makeKey('a')),
          text: String(answer.content.text || ''), match_text: String(answer.content.match_text || ''), image_id: number(answer.content.image_id),
          image_url: String(answer.content.image_url || ''), emoji: String(answer.content.emoji || ''), icon: String(answer.content.icon || ''),
          personality_weights: answer.content.personality_weights && typeof answer.content.personality_weights === 'object' ? {...answer.content.personality_weights} : {}
        };
        answer.is_correct = bool(answer.is_correct);
        answer.score = number(answer.score);
        return answer;
      }) : [];
      return normaliseQuestionCorrectness(question);
    }) : [];
    return normaliseQuizCorrectness(quiz);
  };

  let state = {
    view: 'list', portalView: window.WPQS?.initialTab || 'dashboard', tab: 'questions', quizzes: [], quiz: null, dashboardAnalytics: null,
    analytics: null, revisions: null, questionBank: [], categories: [], templates: [], organizations: [], team: null, workspace: null, userWorkspaces: null, activity: [], me: null, dashboard: null,
    dirty: false, saving: false, loadingPanel: false,
    listQuery: '', listStatus: 'all', listType: 'all', listCategory: 'all', listScope: 'all', listWorkflow: 'all', listCreator: 'all', listSort: localStorage.getItem('wpqs_list_sort') || 'updated_desc', listView: localStorage.getItem('wpqs_list_view') || 'grid',
    analyticsPreset: '30', analyticsFrom: '', analyticsTo: '', analyticsGroup: 'day',
    userPreferences: normaliseUserPreferences(WPQS.userPreferences), categoryQuery: '', userWorkspaceQuery: '', organizationEditor: null, docsQuery: '', docsRole: 'all', workflow: null, openQuestionSettings: null,
    activeQuestionKey: '', validationIssues: [], dragQuestionIndex: null, dragAnswer: null, conflict: null, systemHealth: null, autosaveTimer: null, autosaveFailures: 0, online: navigator.onLine,
    preview: {started: false, index: 0, responses: {}, orderSeeds: {}, feedback: null, complete: false}
  };

  applyUserPreferences(state.userPreferences);

  const recoveryKey = quiz => `quiz_atelier_recovery_${number(WPQS.context?.user_id || state.me?.id)}_${number(quiz?.id) || 'new'}`;
  const storeRecovery = () => {
    if (!state.quiz || state.view !== 'builder') return;
    try { localStorage.setItem(recoveryKey(state.quiz), JSON.stringify({saved_at:Date.now(), quiz:state.quiz})); } catch (_) {}
  };
  const removeRecovery = quiz => { try { localStorage.removeItem(recoveryKey(quiz)); } catch (_) {} };
  const recoverQuiz = quiz => {
    try {
      const raw = localStorage.getItem(recoveryKey(quiz));
      if (!raw) return quiz;
      const recovery = JSON.parse(raw);
      const serverTime = quiz.updated_at ? new Date(String(quiz.updated_at).replace(' ','T')+'Z').getTime() : 0;
      if (!recovery?.quiz || number(recovery.saved_at) <= serverTime) { removeRecovery(quiz); return quiz; }
      if (confirm('Βρέθηκε νεότερη τοπική αυτόματη ανάκτηση για αυτό το quiz. Να επαναφερθεί;')) return normaliseQuiz(recovery.quiz);
      removeRecovery(quiz);
    } catch (_) {}
    return quiz;
  };

  const markDirty = () => {
    state.dirty = true;
    const label = root.querySelector('.saved');
    if (label) label.textContent = state.online ? '● Μη αποθηκευμένες αλλαγές' : '● Offline — αποθηκεύτηκε τοπικά';
    storeRecovery();
    window.clearTimeout(state.autosaveTimer);
    if (!state.conflict && state.online) {
      state.autosaveTimer = window.setTimeout(() => {
        if (state.view === 'builder' && state.quiz && state.dirty && !state.saving && !state.conflict) save(null, true);
      }, 4000);
    }
  };

  const toast = message => {
    const old = document.querySelector('.wpqs-toast');
    if (old) old.remove();
    const element = document.createElement('div');
    element.className = 'wpqs-toast';
    element.textContent = message;
    document.body.appendChild(element);
    setTimeout(() => element.remove(), 2600);
  };


  /**
   * WordPress pages often wrap shortcode content in a form. Any button without an
   * explicit type may therefore submit the outer form and destroy unsaved state.
   */
  const ensureButtonTypes = (scope = root) => {
    scope.querySelectorAll('button:not([type])').forEach(button => { button.type = 'button'; });
  };

  /** Keeps the same question at the same viewport position after a local rerender. */
  const preserveBuilderPosition = (callback, focusSelector = '') => {
    const workspace = root.querySelector('.wpqs-builder .workspace');
    const activeCard = document.activeElement?.closest?.('[data-question-card]');
    const anchorSelector = focusSelector || (activeCard ? `[data-question-card="${activeCard.dataset.questionCard}"]` : '');
    const anchor = anchorSelector ? root.querySelector(anchorSelector) : null;
    const anchorTop = anchor?.getBoundingClientRect().top ?? null;
    const pageX = window.scrollX;
    const pageY = window.scrollY;
    const workspaceX = workspace ? workspace.scrollLeft : 0;
    const workspaceY = workspace ? workspace.scrollTop : 0;
    const html = document.documentElement;
    const previousBehavior = html.style.scrollBehavior;
    html.style.scrollBehavior = 'auto';
    callback();
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const nextWorkspace = root.querySelector('.wpqs-builder .workspace');
      if (nextWorkspace) {
        nextWorkspace.scrollLeft = workspaceX;
        nextWorkspace.scrollTop = workspaceY;
      }
      const nextAnchor = anchorSelector ? root.querySelector(anchorSelector) : null;
      if (anchorTop !== null && nextAnchor) {
        window.scrollBy({left: 0, top: nextAnchor.getBoundingClientRect().top - anchorTop, behavior: 'auto'});
      } else {
        window.scrollTo({left: pageX, top: pageY, behavior: 'auto'});
      }
      if (focusSelector) {
        const field = root.querySelector(focusSelector);
        field?.focus({preventScroll: true});
        if (field && typeof field.setSelectionRange === 'function' && !['SELECT','BUTTON'].includes(field.tagName)) {
          const length = String(field.value || '').length;
          field.setSelectionRange(length, length);
        }
      }
      html.style.scrollBehavior = previousBehavior;
    }));
  };

  const collectValidationIssues = (quiz, publishing = false) => {
    const issues = [];
    const push = (message, questionIndex = null, selector = '') => issues.push({message, questionIndex, selector});
    if (!String(quiz.title || '').trim()) push('Συμπληρώστε τον τίτλο του quiz.', null, '[data-field="title"]');
    if (!publishing) return issues;
    if (!Array.isArray(quiz.questions) || !quiz.questions.length) push('Προσθέστε τουλάχιστον μία ερώτηση πριν από τη δημοσίευση.', null, '[data-add-question]');

    (quiz.questions || []).forEach((question, index) => {
      const label = `Ερώτηση ${index + 1}`;
      const selector = `[data-question-card="${index}"]`;
      if (!String(question.content?.title || '').trim()) push(`${label}: λείπει το κείμενο της ερώτησης.`, index, `[data-question-title="${index}"]`);
      const answers = Array.isArray(question.answers) ? question.answers : [];
      const answerTypes = ['multiple_choice','multiple_answers','true_false','image_choice','poll','open_text','ordering','ranking','matching'];
      const minimum = question.type === 'open_text' ? 1 : 2;
      if (answerTypes.includes(question.type) && answers.length < minimum) push(`${label}: χρειάζονται τουλάχιστον ${minimum} ${minimum === 1 ? 'απάντηση' : 'απαντήσεις'}.`, index, selector);
      answers.forEach((answer, answerIndex) => {
        if (answerTypes.includes(question.type) && !String(answer.content?.text || '').trim()) push(`${label}: η απάντηση ${answerIndex + 1} είναι κενή.`, index, `[data-answer-question="${index}"][data-answer="${answerIndex}"]`);
        if (question.type === 'image_choice' && !String(answer.content?.image_url || '').trim()) push(`${label}: η απάντηση ${answerIndex + 1} χρειάζεται εικόνα.`, index, selector);
        if (question.type === 'matching' && !String(answer.content?.match_text || '').trim()) push(`${label}: λείπει η δεξιά τιμή στο ζεύγος ${answerIndex + 1}.`, index, `[data-answer-match][data-question="${index}"][data-answer="${answerIndex}"]`);
      });

      if (question.type === 'true_false' && answers.length !== 2) push(`${label}: ο τύπος Σωστό / Λάθος πρέπει να έχει ακριβώς δύο επιλογές.`, index, selector);
      if (quiz.quiz_type !== 'personality' && !['poll','ordering','ranking','matching','slider','numeric','rating'].includes(question.type)) {
        const correct = answers.filter(answer => bool(answer.is_correct)).length;
        if (['multiple_choice','true_false','image_choice'].includes(question.type) && correct !== 1) push(`${label}: επιλέξτε ακριβώς μία σωστή απάντηση.`, index, selector);
        if (['multiple_answers','open_text'].includes(question.type) && correct < 1) push(`${label}: επιλέξτε τουλάχιστον μία σωστή ή αποδεκτή απάντηση.`, index, selector);
      }
      if (question.type === 'numeric' && !Number.isFinite(Number(question.settings?.numeric_answer))) push(`${label}: ορίστε έγκυρη αριθμητική απάντηση.`, index, `[data-question-setting="numeric_answer"][data-index="${index}"]`);
      if (question.type === 'slider') {
        const min = number(question.settings?.slider_min); const max = number(question.settings?.slider_max); const step = number(question.settings?.slider_step);
        if (max <= min) push(`${label}: το μέγιστο της κλίμακας πρέπει να είναι μεγαλύτερο από το ελάχιστο.`, index, `[data-question-setting="slider_max"][data-index="${index}"]`);
        if (step <= 0) push(`${label}: το βήμα της κλίμακας πρέπει να είναι μεγαλύτερο από μηδέν.`, index, `[data-question-setting="slider_step"][data-index="${index}"]`);
        if (number(question.settings?.correct_min) < min || number(question.settings?.correct_max) > max || number(question.settings?.correct_min) > number(question.settings?.correct_max)) push(`${label}: το σωστό εύρος πρέπει να βρίσκεται μέσα στα όρια της κλίμακας.`, index, selector);
      }
      if (question.type === 'rating') {
        const ratingMax = number(question.settings?.rating_max, 5);
        if (ratingMax < 2 || ratingMax > 20) push(`${label}: η μέγιστη αξιολόγηση πρέπει να είναι από 2 έως 20.`, index, `[data-question-setting="rating_max"][data-index="${index}"]`);
        if (number(question.settings?.correct_min) < 1 || number(question.settings?.correct_max) > ratingMax || number(question.settings?.correct_min) > number(question.settings?.correct_max)) push(`${label}: το σωστό εύρος αξιολόγησης δεν είναι έγκυρο.`, index, selector);
      }
      if (question.settings?.condition?.enabled) {
        const previousKeys = new Set((quiz.questions || []).slice(0,index).map(item => String(item.settings?.key || '')));
        const rules = Array.isArray(question.settings.condition.rules) ? question.settings.condition.rules : [];
        if (!rules.length) push(`${label}: ενεργοποιήθηκαν όροι εμφάνισης χωρίς κανόνα.`, index, selector);
        rules.forEach(rule => { if (!previousKeys.has(String(rule.question_key || ''))) push(`${label}: ένας κανόνας εμφάνισης αναφέρεται σε μη έγκυρη προηγούμενη ερώτηση.`, index, selector); });
      }
    });

    if (quiz.quiz_type === 'personality' && (quiz.settings?.personality_profiles || []).length < 2) push('Το Personality Test χρειάζεται τουλάχιστον δύο προφίλ αποτελέσματος.', null, '[data-tab="results"]');
    return issues.filter((issue, index, all) => all.findIndex(item => item.message === issue.message && item.selector === issue.selector) === index);
  };

  const validateQuiz = (quiz, publishing = false) => collectValidationIssues(quiz, publishing).map(issue => issue.message);

  const formatDate = value => {
    if (!value) return '—';
    const parsed = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
  };

  const localDateTime = value => {
    if (!value) return '';
    const parsed = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
    if (Number.isNaN(parsed.getTime())) return '';
    const offset = parsed.getTimezoneOffset();
    return new Date(parsed.getTime() - offset * 60000).toISOString().slice(0, 16);
  };

  const load = async () => {
    try {
      const base = await Promise.all([
        api('quizzes'), api('question-bank'), api('categories'), api('me'), api('dashboard'), api('templates'),
        WPQS.canAnalytics ? api('analytics') : Promise.resolve(null)
      ]);
      state.quizzes = (Array.isArray(base[0]) ? base[0] : []).map(normaliseQuiz);
      state.questionBank = Array.isArray(base[1]) ? base[1] : [];
      state.categories = Array.isArray(base[2]) ? base[2] : [];
      state.me = base[3] || null;
      state.dashboard = base[4] || null;
      state.templates = Array.isArray(base[5]) ? base[5] : [];
      state.dashboardAnalytics = base[6] || null;
      const orgId = number(state.me?.context?.organization_id || WPQS.context?.organization_id);
      if (WPQS.canManageTeam && orgId) {
        state.team = await api(`organizations/${orgId}/members`);
        try { state.workspace = await api('workspace'); } catch (_) { state.workspace = null; }
        try { state.activity = await api('activity'); } catch (_) { state.activity = []; }
      }
      if (WPQS.canManageOrganizations) state.organizations = await api('organizations');
      if (WPQS.canManageUserWorkspaces) {
        try { state.userWorkspaces = await api('admin/user-workspaces'); } catch (_) { state.userWorkspaces = null; }
      }
      routePortal(state.portalView);
    } catch (error) {
      root.innerHTML = `<p class="wpqs-notice">${esc(error.message)}</p>`;
    }
  };


  const getQuizCover = quiz => String(quiz?.settings?.intro?.image_url || '');

  const copyText = async value => {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
      } else {
        const field = document.createElement('textarea');
        field.value = value;
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        field.remove();
      }
      toast('Ο κώδικας αντιγράφηκε');
    } catch (_) {
      alert('Δεν ήταν δυνατή η αντιγραφή. Επιλέξτε τον κώδικα και αντιγράψτε τον χειροκίνητα.');
    }
  };

  const openEmbedModal = quiz => {
    document.querySelector('.wpqs-modal-backdrop')?.remove();
    const embedUrl = `${WPQS.site}wpqs-embed/${quiz.id}/`;
    const scriptUrl = `${WPQS.site}wpqs-embed.js`;
    const directUrl = embedUrl;
    const modal = document.createElement('div');
    modal.className = 'wpqs-modal-backdrop';
    modal.innerHTML = `<section class="wpqs-modal" role="dialog" aria-modal="true" aria-labelledby="wpqs-embed-title"><header class="wpqs-modal-head"><div><span class="wpqs-kicker">EMBED & SHARE CENTER</span><h2 id="wpqs-embed-title">${esc(quiz.title)}</h2><p>Iframe, JavaScript, WordPress, Drupal, direct URL και responsive preview.</p></div><button class="wpqs-modal-close" data-modal-close aria-label="Κλείσιμο">×</button></header><div class="wpqs-embed-layout"><div class="wpqs-embed-config"><div class="wpqs-embed-tabs" role="tablist"><button class="is-active" data-embed-mode="iframe">Iframe</button><button data-embed-mode="javascript">JavaScript</button><button data-embed-mode="shortcode">WordPress</button><button data-embed-mode="drupal">Drupal</button><button data-embed-mode="direct">Direct URL</button></div><div class="wpqs-embed-options-grid" data-embed-options><label>Πλάτος<select data-embed-width><option value="100%">100% responsive</option><option value="960px">960px</option><option value="800px">800px</option><option value="640px">640px</option></select></label><label>Ύψος<input type="number" min="360" max="1800" step="20" value="720" data-embed-height></label><label>Border radius<input type="number" min="0" max="80" value="0" data-embed-radius></label><label>Background<input type="color" value="#08080a" data-embed-background></label><label>Loading<select data-embed-loading><option value="lazy">Lazy</option><option value="eager">Άμεσο</option></select></label><label>Responsive<select data-embed-responsive><option value="yes">Ναι</option><option value="no">Όχι</option></select></label><label class="check"><input type="checkbox" data-embed-title checked> Προσθήκη title για accessibility</label></div><label class="wpqs-code-label">Κώδικας<textarea readonly data-embed-code></textarea></label><div class="wpqs-modal-actions"><button class="wpqs-primary" data-copy-embed>Αντιγραφή</button><button data-open-external>Άνοιγμα εξωτερικά ↗</button></div><div class="wpqs-embed-note" data-embed-help>Το iframe λειτουργεί σε οποιοδήποτε CMS που επιτρέπει Custom HTML.</div></div><div class="wpqs-embed-preview"><div class="wpqs-preview-toolbar"><strong>Ζωντανή προεπισκόπηση</strong><span>${esc(statusLabel(quiz.status))}</span></div><iframe class="wpqs-embed-preview-frame" src="${esc(embedUrl)}" title="Προεπισκόπηση ${esc(quiz.title)}" loading="lazy"></iframe></div></div></section>`;
    document.body.appendChild(modal);
    let mode = 'iframe';
    const codeField = modal.querySelector('[data-embed-code]');
    const previewFrame = modal.querySelector('.wpqs-embed-preview iframe');
    const optionsBox = modal.querySelector('[data-embed-options]');
    const help = modal.querySelector('[data-embed-help]');
    const update = () => {
      const height = Math.max(360, Math.min(1800, number(modal.querySelector('[data-embed-height]').value, 720)));
      const width = modal.querySelector('[data-embed-width]').value;
      const radius = Math.max(0, Math.min(80, number(modal.querySelector('[data-embed-radius]').value, 0)));
      const background = modal.querySelector('[data-embed-background]').value || '#08080a';
      const loading = modal.querySelector('[data-embed-loading]').value;
      const responsive = modal.querySelector('[data-embed-responsive]').value === 'yes';
      const withTitle = modal.querySelector('[data-embed-title]').checked;
      const titleAttr = withTitle ? ` title="${esc(quiz.title)}"` : '';
      const iframeStyle = `border:0;border-radius:${radius}px;background:${background};max-width:100%;${responsive ? 'width:100%;' : ''}`;
      let code = '';
      if (mode === 'javascript') {
        code = `<div data-wpqs-quiz="${quiz.id}" data-width="${width}" data-height="${height}" data-radius="${radius}" data-background="${background}" data-loading="${loading}"${withTitle ? ` data-title="${esc(quiz.title)}"` : ''}></div>\n<script src="${scriptUrl}" async></script>`;
        help.innerHTML = 'Προσθέστε το div και το script σε Custom HTML. Το loader δημιουργεί ασφαλές iframe και εφαρμόζει τις ρυθμίσεις.';
      } else if (mode === 'shortcode') {
        code = `[wp_quiz_studio id="${quiz.id}"]`;
        help.innerHTML = '<strong>Gutenberg:</strong> προσθέστε block «Shortcode» και επικολλήστε τον κώδικα. <strong>Elementor:</strong> χρησιμοποιήστε widget Shortcode.';
      } else if (mode === 'drupal') {
        code = `<div class="quiz-atelier-embed" style="max-width:${width === '100%' ? '100%' : width};margin:auto">\n  <iframe src="${embedUrl}" width="100%" height="${height}" frameborder="0" loading="${loading}"${titleAttr} style="${iframeStyle}"></iframe>\n</div>`;
        help.innerHTML = '<strong>Drupal:</strong> χρησιμοποιήστε text format που επιτρέπει iframe ή Custom Block → Full HTML. Προσθέστε το domain στη whitelist του quiz.';
      } else if (mode === 'direct') {
        code = directUrl;
        help.innerHTML = 'Ο direct σύνδεσμος ανοίγει το quiz σε ξεχωριστή σελίδα χωρίς WordPress admin chrome.';
      } else {
        code = `<iframe src="${embedUrl}" width="${width}" height="${height}" frameborder="0" loading="${loading}"${titleAttr} style="${iframeStyle}"></iframe>`;
        help.innerHTML = 'Το iframe λειτουργεί σε WordPress Custom HTML, Drupal Full HTML, Joomla, Laravel και απλή HTML.';
      }
      codeField.value = code;
      optionsBox.hidden = ['shortcode','direct'].includes(mode);
      previewFrame.style.height = `${Math.min(height, 820)}px`;
      previewFrame.style.borderRadius = `${radius}px`;
      previewFrame.style.background = background;
    };
    const close = () => modal.remove();
    modal.querySelector('[data-modal-close]').onclick = close;
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    modal.querySelectorAll('[data-embed-mode]').forEach(button => button.onclick = () => { mode = button.dataset.embedMode; modal.querySelectorAll('[data-embed-mode]').forEach(item => item.classList.toggle('is-active', item === button)); update(); });
    modal.querySelectorAll('[data-embed-options] input,[data-embed-options] select').forEach(input => input.addEventListener(input.type === 'number' || input.type === 'color' ? 'input' : 'change', update));
    modal.querySelector('[data-copy-embed]').onclick = () => copyText(codeField.value);
    modal.querySelector('[data-open-external]').onclick = () => window.open(embedUrl, '_blank', 'noopener');
    const escapeHandler = event => { if (event.key === 'Escape') { close(); document.removeEventListener('keydown', escapeHandler); } };
    document.addEventListener('keydown', escapeHandler);
    update(); modal.querySelector('[data-modal-close]').focus();
  };

  const openUserStyleModal = () => {
    document.querySelector('.wpqs-modal-backdrop')?.remove();
    const original = clone(state.userPreferences);
    let draft = clone(state.userPreferences);
    const modal = document.createElement('div');
    modal.className = 'wpqs-modal-backdrop';
    const presetOptions = [
      ['atelier', 'Atelier'], ['midnight', 'Μεσονύκτιο'], ['graphite', 'Graphite'],
      ['contrast', 'Υψηλή αντίθεση'], ['custom', 'Προσαρμοσμένο']
    ].map(([value, label]) => `<option value="${value}" ${draft.preset === value ? 'selected' : ''}>${label}</option>`).join('');
    const styleColor = (key, label) => `<label>${label}<span class="color-control"><input type="color" data-user-style="${key}" value="${esc(draft[key])}"><input data-user-style-text="${key}" value="${esc(draft[key])}"></span></label>`;
    modal.innerHTML = `<section class="wpqs-modal wpqs-style-modal" role="dialog" aria-modal="true" aria-labelledby="wpqs-style-title">
      <header class="wpqs-modal-head"><div><span class="wpqs-kicker">ΠΡΟΣΩΠΙΚΗ ΕΜΦΑΝΙΣΗ</span><h2 id="wpqs-style-title">Το στυλ του λογαριασμού μου</h2><p>Οι αλλαγές αφορούν μόνο το δικό σας Studio και δεν αλλάζουν την εμφάνιση των quiz.</p></div><button class="wpqs-modal-close" data-modal-close aria-label="Κλείσιμο">×</button></header>
      <div class="wpqs-style-layout">
        <div class="wpqs-style-controls">
          <label class="wpqs-preset-label">Έτοιμο στυλ<select data-user-preset>${presetOptions}</select></label>
          <div class="settings-grid two">
            ${styleColor('accent', 'Κύριο accent')}${styleColor('accent_light', 'Φωτεινό accent')}${styleColor('accent_text', 'Κείμενο κύριων κουμπιών')}
            ${styleColor('page', 'Φόντο εφαρμογής')}${styleColor('surface', 'Φόντο καρτών')}
            ${styleColor('surface_raised', 'Ανασηκωμένα στοιχεία')}${styleColor('text', 'Κύριο κείμενο')}
            ${styleColor('muted', 'Δευτερεύον κείμενο')}${styleColor('border', 'Περιγράμματα')}
            <label>Στρογγυλοποίηση<input type="range" min="6" max="32" data-user-style="radius" value="${esc(draft.radius)}"><span data-user-radius>${esc(draft.radius)}px</span></label>
            <label>Πυκνότητα<select data-user-style="density"><option value="compact" ${draft.density === 'compact' ? 'selected' : ''}>Συμπαγής</option><option value="comfortable" ${draft.density === 'comfortable' ? 'selected' : ''}>Άνετη</option><option value="spacious" ${draft.density === 'spacious' ? 'selected' : ''}>Ευρύχωρη</option></select></label>
          </div>
          <div class="contrast-report" data-user-contrast></div>
          <div class="wpqs-modal-actions"><button class="wpqs-primary" data-user-style-save>Αποθήκευση για τον λογαριασμό μου</button><button data-user-auto-contrast>Αυτόματη αντίθεση</button><button data-user-style-reset>Επαναφορά Atelier</button></div>
        </div>
        <div class="wpqs-style-preview"><span class="wpqs-kicker">LIVE PREVIEW</span><div class="wpqs-style-preview-card"><div class="wpqs-style-preview-sidebar"><b>QA</b><i></i><i></i><i></i></div><div class="wpqs-style-preview-content"><small>Quiz Atelier</small><h3>Η προσωπική σας επιφάνεια</h3><p>Κάθε WordPress account μπορεί να αποθηκεύει διαφορετικό χρωματικό στυλ και πυκνότητα.</p><button>Κύρια ενέργεια</button><div><span></span><span></span><span></span></div></div></div></div>
      </div>
    </section>`;
    document.body.appendChild(modal);

    const updateFields = () => {
      Object.entries(draft).forEach(([key, value]) => {
        const input = modal.querySelector(`[data-user-style="${key}"]`);
        if (input) input.value = value;
        const text = modal.querySelector(`[data-user-style-text="${key}"]`);
        if (text) text.value = value;
      });
      const radius = modal.querySelector('[data-user-radius]');
      if (radius) radius.textContent = `${number(draft.radius, 18)}px`;
      const preset = modal.querySelector('[data-user-preset]');
      if (preset) preset.value = draft.preset || 'custom';
    };
    const updateStylePreview = () => {
      applyUserPreferences(draft);
      const preview = modal.querySelector('.wpqs-style-preview-card');
      if (preview) preview.style.cssText = `--style-accent:${draft.accent};--style-accent-text:${draft.accent_text || '#111111'};--style-page:${draft.page};--style-surface:${draft.surface};--style-raised:${draft.surface_raised};--style-text:${draft.text};--style-muted:${draft.muted};--style-border:${draft.border};--style-radius:${number(draft.radius,18)}px`;
      const textRatio = contrastRatio(draft.text, draft.surface);
      const mutedRatio = contrastRatio(draft.muted, draft.surface);
      const accentRatio = contrastRatio(draft.accent_text || '#111111', draft.accent);
      const report = modal.querySelector('[data-user-contrast]');
      if (report) report.innerHTML = `<strong>Αντίθεση interface</strong><span class="${textRatio >= 4.5 ? 'is-good' : 'is-warning'}">Κύριο κείμενο: ${textRatio.toFixed(2)}:1 ${textRatio >= 4.5 ? '✓' : '⚠'}</span><span class="${mutedRatio >= 4.5 ? 'is-good' : 'is-warning'}">Δευτερεύον κείμενο: ${mutedRatio.toFixed(2)}:1 ${mutedRatio >= 4.5 ? '✓' : '⚠'}</span><span class="${accentRatio >= 4.5 ? 'is-good' : 'is-warning'}">Κουμπί: ${accentRatio.toFixed(2)}:1 ${accentRatio >= 4.5 ? '✓' : '⚠'}</span>`;
    };
    const close = (revert = true) => { if (revert) applyUserPreferences(original); modal.remove(); };
    modal.querySelector('[data-modal-close]').onclick = () => close(true);
    modal.addEventListener('click', event => { if (event.target === modal) close(true); });
    modal.querySelector('[data-user-preset]').onchange = event => {
      const selected = event.target.value;
      if (studioStylePresets[selected]) draft = clone(studioStylePresets[selected]);
      else draft.preset = 'custom';
      updateFields(); updateStylePreview();
    };
    modal.querySelectorAll('[data-user-style]').forEach(input => {
      const handler = event => {
        const key = input.dataset.userStyle;
        draft[key] = key === 'radius' ? number(event.target.value, 18) : event.target.value;
        draft.preset = 'custom';
        modal.querySelector('[data-user-preset]').value = 'custom';
        const text = modal.querySelector(`[data-user-style-text="${key}"]`);
        if (text) text.value = draft[key];
        updateStylePreview();
      };
      input.addEventListener(input.type === 'color' || input.type === 'range' ? 'input' : 'change', handler);
    });
    modal.querySelectorAll('[data-user-style-text]').forEach(input => input.onchange = event => {
      const key = input.dataset.userStyleText;
      if (/^#[0-9a-f]{6}$/i.test(event.target.value)) {
        draft[key] = event.target.value;
        draft.preset = 'custom';
        const color = modal.querySelector(`[data-user-style="${key}"]`);
        if (color) color.value = draft[key];
        modal.querySelector('[data-user-preset]').value = 'custom';
        updateStylePreview();
      }
    });
    modal.querySelector('[data-user-auto-contrast]').onclick = () => {
      const white = '#ffffff'; const black = '#111111';
      draft.text = contrastRatio(white, draft.surface) >= contrastRatio(black, draft.surface) ? white : black;
      draft.muted = draft.text === white ? '#d4d4d8' : '#374151';
      draft.accent_light = contrastRatio(white, draft.accent) >= 4.5 ? white : draft.accent;
      draft.accent_text = contrastRatio(white, draft.accent) >= contrastRatio(black, draft.accent) ? white : black;
      draft.preset = 'custom';
      updateFields(); updateStylePreview();
    };
    modal.querySelector('[data-user-style-reset]').onclick = () => { draft = clone(studioStylePresets.atelier); updateFields(); updateStylePreview(); };
    modal.querySelector('[data-user-style-save]').onclick = async () => {
      const button = modal.querySelector('[data-user-style-save]');
      button.disabled = true;
      try {
        state.userPreferences = normaliseUserPreferences(await api('me/preferences', {method: 'PUT', body: JSON.stringify(draft)}));
        applyUserPreferences(state.userPreferences);
        toast('Το προσωπικό σας στυλ αποθηκεύτηκε');
        close(false);
      } catch (error) {
        button.disabled = false;
        alert(error.message);
      }
    };
    const escapeHandler = event => { if (event.key === 'Escape') { close(true); document.removeEventListener('keydown', escapeHandler); } };
    document.addEventListener('keydown', escapeHandler);
    updateFields(); updateStylePreview();
    modal.querySelector('[data-user-preset]').focus();
  };

  const portalIcon = name => {
    const paths = {
      dashboard: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
      library: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V4H6.5A2.5 2.5 0 0 0 4 6.5z"/><path d="M8 7h8M8 11h8"/>',
      templates: '<path d="m12 2 8 4-8 4-8-4z"/><path d="m4 10 8 4 8-4M4 14l8 4 8-4"/>',
      categories: '<path d="M3 7h7l2 2h9v10H3z"/><path d="M3 7V5h7l2 2"/>',
      analytics: '<path d="M4 20V10M10 20V4M16 20v-7M22 20V7"/>',
      team: '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0M14 15.5a4.5 4.5 0 0 1 6.5 4"/>',
      activity: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5M12 7v5l3 2"/>',
      organizations: '<path d="M4 21V8l8-5 8 5v13M8 21v-5h8v5M8 10h1M12 10h1M16 10h1"/>',
      workspace: '<path d="M3 6h18v14H3zM7 3h10v3M7 11h10M7 15h6"/>',
      users: '<circle cx="8" cy="8" r="3"/><circle cx="17" cy="7" r="2.5"/><path d="M2.5 20a5.5 5.5 0 0 1 11 0M14 13h7M17.5 9.5v7"/>',
      documentation: '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5zM20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5z"/>',
      profile: '<circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/>',
      system: '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/><circle cx="12" cy="12" r="3"/>'
    };
    return `<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${paths[name] || paths.dashboard}</svg>`;
  };

  const portalNav = active => {
    const links = [
      ['dashboard','dashboard','Dashboard'], ['library','library','Quiz Library'], ['templates','templates','Templates'], ['categories','categories','Κατηγορίες']
    ];
    if (WPQS.canAnalytics) links.push(['analytics-global','analytics','Analytics']);
    if (WPQS.canManageTeam) links.push(['workspace','workspace','Το Workspace μου'], ['team','team','Η ομάδα μου'], ['activity','activity','Activity Log']);
    if (WPQS.canManageUserWorkspaces) links.push(['user-workspaces','users','Χρήστες & Workspaces'], ['system','system','System Status']);
    if (WPQS.canManageOrganizations) links.push(['organizations','organizations','Organizations']);
    links.push(['documentation','documentation','Τεκμηρίωση'], ['profile','profile','Ο λογαριασμός μου']);
    const frontClass = WPQS.isFront ? ' is-front' : '';
    const brand = WPQS.isFront ? '' : '<div class="wpqs-portal-brand"><b>Quiz</b><span>ATELIER</span></div>';
    const account = `<div class="wpqs-portal-account">${WPQS.isFront ? '' : `<span>${esc(state.me?.display_name || WPQS.userName || '')}</span><button type="button" data-user-style-open title="Το στυλ μου" aria-label="Το στυλ μου">◐</button>`}</div>`;
    const buttons = links.map(([view,icon,label]) => `<button type="button" class="${active === view ? 'is-active' : ''}" data-portal-view="${view}"><i>${portalIcon(icon)}</i><span>${esc(label)}</span></button>`).join('');
    return `<header class="wpqs-portal-topbar${frontClass}">${brand}<div class="wpqs-portal-nav-shell" data-wpqs-scroll-menu><button type="button" class="wpqs-portal-nav-arrow wpqs-portal-nav-prev" aria-label="Μετακίνηση menu αριστερά">‹</button><div class="wpqs-portal-nav-viewport"><nav class="wpqs-portal-nav-track" aria-label="Κύρια πλοήγηση Quiz Atelier">${buttons}</nav></div><button type="button" class="wpqs-portal-nav-arrow wpqs-portal-nav-next" aria-label="Μετακίνηση menu δεξιά">›</button></div>${account}</header>`;
  };

  const bindPortalNav = () => {
    ensureButtonTypes();
    root.querySelectorAll('[data-portal-view]').forEach(button => button.onclick = () => routePortal(button.dataset.portalView));
    root.querySelectorAll('[data-user-style-open]').forEach(button => button.addEventListener('click', openUserStyleModal));

    root.querySelectorAll('[data-wpqs-scroll-menu]').forEach(wrapper => {
      const viewport = wrapper.querySelector('.wpqs-portal-nav-viewport');
      const track = wrapper.querySelector('.wpqs-portal-nav-track');
      const previousButton = wrapper.querySelector('.wpqs-portal-nav-prev');
      const nextButton = wrapper.querySelector('.wpqs-portal-nav-next');
      if (!viewport || !track || !previousButton || !nextButton) return;

      let dragging = false;
      let dragMoved = false;
      let startX = 0;
      let startScrollLeft = 0;
      const hasOverflow = () => viewport.scrollWidth > viewport.clientWidth + 2;
      const maxScroll = () => Math.max(0, viewport.scrollWidth - viewport.clientWidth);
      const updateState = () => {
        const overflow = hasOverflow();
        const left = viewport.scrollLeft > 2;
        const right = viewport.scrollLeft < maxScroll() - 2;
        wrapper.classList.toggle('has-overflow', overflow);
        wrapper.classList.toggle('has-left-overflow', overflow && left);
        wrapper.classList.toggle('has-right-overflow', overflow && right);
        viewport.classList.toggle('is-draggable', overflow);
        previousButton.disabled = !left;
        nextButton.disabled = !right;
      };
      const scrollByAmount = amount => viewport.scrollBy({left: amount, behavior: 'smooth'});

      previousButton.addEventListener('click', () => scrollByAmount(-Math.max(220, viewport.clientWidth * .65)));
      nextButton.addEventListener('click', () => scrollByAmount(Math.max(220, viewport.clientWidth * .65)));
      viewport.addEventListener('wheel', event => {
        if (!hasOverflow()) return;
        const movement = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
        if (!movement) return;
        event.preventDefault();
        viewport.scrollLeft += movement;
      }, {passive:false});
      viewport.addEventListener('pointerdown', event => {
        if (!hasOverflow() || event.pointerType === 'touch' || event.button !== 0) return;
        dragging = true; dragMoved = false; startX = event.clientX; startScrollLeft = viewport.scrollLeft;
        viewport.classList.add('is-dragging');
        viewport.setPointerCapture?.(event.pointerId);
      });
      viewport.addEventListener('pointermove', event => {
        if (!dragging) return;
        const distance = event.clientX - startX;
        if (Math.abs(distance) > 4) dragMoved = true;
        viewport.scrollLeft = startScrollLeft - distance;
      });
      const stopDragging = event => {
        if (!dragging) return;
        dragging = false;
        viewport.classList.remove('is-dragging');
        if (event && viewport.hasPointerCapture?.(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
      };
      viewport.addEventListener('pointerup', stopDragging);
      viewport.addEventListener('pointercancel', stopDragging);
      viewport.addEventListener('lostpointercapture', stopDragging);
      viewport.addEventListener('click', event => {
        if (!dragMoved) return;
        event.preventDefault(); event.stopPropagation(); dragMoved = false;
      }, true);
      viewport.addEventListener('scroll', updateState, {passive:true});
      window.addEventListener('resize', updateState, {passive:true});
      if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(updateState);
        observer.observe(viewport); observer.observe(track);
      }
      requestAnimationFrame(() => {
        const activeButton = track.querySelector('.is-active');
        activeButton?.scrollIntoView({behavior:'auto', block:'nearest', inline:'center'});
        updateState();
      });
    });
  };

  const routePortal = view => {
    state.portalView = view || 'dashboard';
    if (state.portalView === 'library') return renderList();
    if (state.portalView === 'templates') return renderTemplates();
    if (state.portalView === 'categories') return renderCategoriesPage();
    if (state.portalView === 'workspace') return renderWorkspace();
    if (state.portalView === 'team') return renderTeam();
    if (state.portalView === 'activity') return renderActivity();
    if (state.portalView === 'user-workspaces') return renderUserWorkspaces();
    if (state.portalView === 'system') return renderSystemStatus();
    if (state.portalView === 'organizations') return renderOrganizations();
    if (state.portalView === 'documentation') return renderDocumentation();
    if (state.portalView === 'profile') return renderProfile();
    if (state.portalView === 'analytics-global') return openGlobalAnalytics();
    return renderDashboard();
  };

  const renderDashboard = () => {
    state.view = 'dashboard';
    const d = state.dashboard || {};
    const org = d.organization || state.me?.organization || {};
    const used = number(org.used_seats);
    const limit = Math.max(1, number(org.seat_limit, 1));
    const seatPercent = Math.min(100, Math.round((used / limit) * 100));
    const recent = state.quizzes.slice(0, 5);
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page">${portalNav('dashboard')}
      <section class="wpqs-dashboard-hero wpqs-org-hero"><div><span class="wpqs-kicker">${esc(org.name || 'QUIZ ATELIER')}</span><h1>Καλώς ήρθατε, ${esc(state.me?.display_name || WPQS.userName || '')}</h1><p>Δημιουργήστε, εγκρίνετε και παρακολουθήστε τα quiz του οργανισμού σας από ένα ενιαίο workspace.</p></div><div class="wpqs-header-actions"><button class="wpqs-ghost" data-dashboard-templates>Templates</button><button class="wpqs-primary" data-dashboard-new>+ Νέο Quiz</button></div></section>
      <section class="wpqs-cards wpqs-summary-cards wpqs-dashboard-metrics"><article><span>Συνολικά quiz</span><b>${number(d.total_quizzes, state.quizzes.length)}</b><small>στο ορατό scope</small></article><article><span>Δημοσιευμένα</span><b>${number(d.published_quizzes)}</b><small>ενεργά</small></article><article><span>Συνολικές απαντήσεις</span><b>${number(d.completions)}</b><small>ολοκληρώσεις</small></article><article><span>Completion rate</span><b>${number(d.completion_rate)}%</b><small>προβολές → ολοκλήρωση</small></article></section>
      <div class="wpqs-dashboard-grid"><section class="wpqs-list wpqs-recent-panel"><div class="section-heading"><div><h2>Τελευταία επεξεργασμένα</h2><p>Συνεχίστε γρήγορα από εκεί που μείνατε.</p></div><button class="wpqs-link" data-dashboard-library>Όλη η βιβλιοθήκη →</button></div>${recent.map(quiz => `<article class="wpqs-mini-quiz"><div class="wpqs-mini-cover">${getQuizCover(quiz) ? `<img src="${esc(getQuizCover(quiz))}" alt="">` : '<b>Q</b>'}</div><div><strong>${esc(quiz.title)}</strong><small>${esc(visibilityLabel(quiz.visibility_scope))} · ${esc(workflowLabel(quiz.workflow_status))} · ${esc(formatDate(quiz.updated_at))}</small></div><button data-dashboard-edit="${quiz.id}">Επεξεργασία</button></article>`).join('') || '<div class="empty-panel">Δεν υπάρχουν ακόμη quiz.</div>'}</section>
        <aside class="wpqs-dashboard-side"><section class="panel wpqs-seat-card"><div class="section-heading"><div><h3>Θέσεις οργανισμού</h3><p>${used} από ${limit} χρησιμοποιούνται</p></div><b>${Math.max(0, limit-used)}</b></div><div class="wpqs-seat-progress"><i style="width:${seatPercent}%"></i></div>${WPQS.canManageTeam ? '<button data-dashboard-team>Διαχείριση ομάδας</button>' : ''}</section>
        <section class="panel"><h3>Workflow</h3><div class="wpqs-workflow-counts"><span><b>${number(d.draft_quizzes)}</b> Drafts</span><span><b>${number(d.pending_review)}</b> Για έγκριση</span><span><b>${number(d.available_seats)}</b> Διαθέσιμες θέσεις</span></div></section></aside></div>
      ${WPQS.canManageTeam ? `<section class="wpqs-list"><div class="section-heading"><div><h2>Πρόσφατη δραστηριότητα</h2><p>Οι τελευταίες αλλαγές στο Organization.</p></div><button class="wpqs-link" data-dashboard-activity>Πλήρες log →</button></div>${activityRows((d.recent_activity || []).slice(0,6))}</section>` : ''}
    </main>`;
    bindPortalNav();
    root.querySelector('[data-dashboard-new]').onclick = () => openQuiz(emptyQuiz());
    root.querySelector('[data-dashboard-templates]').onclick = () => routePortal('templates');
    root.querySelector('[data-dashboard-library]').onclick = () => routePortal('library');
    root.querySelector('[data-dashboard-team]')?.addEventListener('click', () => routePortal('team'));
    root.querySelector('[data-dashboard-activity]')?.addEventListener('click', () => routePortal('activity'));
    root.querySelectorAll('[data-dashboard-edit]').forEach(button => button.onclick = async () => openQuiz(await api(`quizzes/${button.dataset.dashboardEdit}`)));
  };

  const templateCard = template => `<article class="wpqs-template-card"><div class="wpqs-template-art ${esc(template.quiz_type)}">${template.thumbnail_url ? `<img src="${esc(template.thumbnail_url)}" alt="">` : `<span>${esc(quizTypeLabel(template.quiz_type))}</span><b>Q</b>`}</div><div><span class="wpqs-scope-badge ${esc(template.scope)}">${template.scope === 'universal' ? 'Universal' : 'Organization'}</span><h3>${esc(template.title)}</h3><p>${esc(template.description || '')}</p><div><button class="wpqs-primary" data-template-use="${template.id}">Χρήση template</button><button data-template-preview="${template.id}">Preview</button>${(WPQS.canManageUniversal || template.scope !== 'universal') ? `<button class="danger" data-template-delete="${template.id}">Διαγραφή</button>` : ''}</div></div></article>`;

  const renderTemplates = () => {
    state.view = 'templates';
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page">${portalNav('templates')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">TEMPLATE LIBRARY</span><h1>Έτοιμες δομές Quiz</h1><p>Ξεκινήστε από Knowledge, Personality, Training, Product Recommendation, Feedback ή Assessment template.</p></div></header><section class="wpqs-template-grid">${state.templates.map(templateCard).join('') || '<div class="empty-panel">Δεν υπάρχουν templates.</div>'}</section></main>`;
    bindPortalNav();
    root.querySelectorAll('[data-template-use]').forEach(button => button.onclick = async () => {
      const template = await api(`templates/${button.dataset.templateUse}`);
      const quiz = normaliseQuiz({...clone(template.snapshot), id: undefined, title: `${template.title} — Νέο`, slug:'', status:'draft', workflow_status:'draft', visibility_scope:'personal', organization_id:number(state.me?.context?.organization_id), template_id:template.id});
      quiz.questions.forEach(question => { delete question.id; delete question.quiz_id; question.answers.forEach(answer => { delete answer.id; delete answer.question_id; }); });
      openQuiz(quiz);
    });
    root.querySelectorAll('[data-template-preview]').forEach(button => button.onclick = async () => { const template = await api(`templates/${button.dataset.templatePreview}`); alert(`${template.title}\n\n${template.description || 'Χωρίς περιγραφή'}\n\n${(template.snapshot?.questions || []).length} ερωτήσεις`); });
    root.querySelectorAll('[data-template-delete]').forEach(button => button.onclick = async () => { if (!confirm('Να διαγραφεί το template;')) return; await api(`templates/${button.dataset.templateDelete}`, {method:'DELETE'}); state.templates = await api('templates'); renderTemplates(); });
  };

  const activityLabel = action => ({quiz_created:'Δημιουργία quiz',quiz_updated:'Επεξεργασία quiz',quiz_deleted:'Διαγραφή quiz',quiz_submitted:'Υποβολή για έλεγχο',quiz_changes_requested:'Ζητήθηκαν αλλαγές',quiz_approved:'Έγκριση quiz',quiz_published:'Δημοσίευση quiz',member_added:'Προσθήκη μέλους',member_updated:'Αλλαγή μέλους',member_removed:'Αφαίρεση μέλους',invitation_sent:'Αποστολή πρόσκλησης',invitation_resent:'Επαναποστολή πρόσκλησης',invitation_revoked:'Ανάκληση πρόσκλησης',template_created:'Δημιουργία template',organization_created:'Δημιουργία Organization',organization_updated:'Ενημέρωση Organization',workspace_updated:'Ενημέρωση Workspace',user_workspace_changed:'Αλλαγή Workspace χρήστη',profile_updated:'Ενημέρωση profile'}[action] || action.replaceAll('_',' '));
  const activityRows = rows => `<div class="wpqs-activity-list">${(rows || []).map(item => `<article><i></i><div><strong>${esc(activityLabel(item.action))}</strong><p>${esc(item.display_name || 'System')} · ${esc(item.object_type)}${item.object_id ? ` #${item.object_id}` : ''}</p></div><time>${esc(formatDate(item.created_at))}</time></article>`).join('') || '<div class="empty-panel">Δεν υπάρχει καταγεγραμμένη δραστηριότητα.</div>'}</div>`;

  const renderActivity = async () => {
    state.view = 'activity';
    if (!state.activity.length && WPQS.canManageTeam) { try { state.activity = await api('activity'); } catch (_) {} }
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page">${portalNav('activity')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">AUDIT & SECURITY</span><h1>Activity Log</h1><p>Ποιος δημιούργησε, άλλαξε, ενέκρινε, δημοσίευσε ή διέγραψε περιεχόμενο.</p></div></header><section class="wpqs-list">${activityRows(state.activity)}</section></main>`;
    bindPortalNav();
  };

  const teamMemberRow = member => {
    const protectedAdministrator = bool(member.is_wordpress_admin) && !bool(WPQS.context?.is_super_admin);
    const disabled = protectedAdministrator ? 'disabled aria-disabled="true"' : '';
    const roleBadge = bool(member.is_wordpress_admin) ? '<span class="wpqs-protected-badge">WordPress Administrator</span>' : '';
    const actions = protectedAdministrator
      ? '<span class="wpqs-protected-note">Προστατευμένος λογαριασμός</span>'
      : `<button class="wpqs-link" data-member-save="${member.id}">Αποθήκευση</button><button class="wpqs-link danger" data-member-remove="${member.id}">Αφαίρεση</button>`;
    return `<tr class="${protectedAdministrator ? 'is-protected-user' : ''}"><td><div class="wpqs-user-cell"><span>${esc((member.display_name || member.user_email).slice(0,1).toUpperCase())}</span><div><strong>${esc(member.display_name)}</strong><small>${esc(member.user_email)}</small>${roleBadge}</div></div></td><td><select data-member-role="${member.id}" ${disabled}><option value="creator_admin" ${member.org_role==='creator_admin'?'selected':''}>Creator Admin</option><option value="creator" ${member.org_role==='creator'?'selected':''}>Quiz Creator</option><option value="viewer" ${member.org_role==='viewer'?'selected':''}>Viewer</option></select></td><td><select data-member-status="${member.id}" ${disabled}><option value="active" ${member.status==='active'?'selected':''}>Active</option><option value="suspended" ${member.status==='suspended'?'selected':''}>Suspended</option></select></td><td>${esc(formatDate(member.joined_at))}</td><td><div class="wpqs-row-actions">${actions}</div></td></tr>`;
  };

  const renderTeam = async () => {
    const orgId = number(state.me?.context?.organization_id);
    if (!state.team && orgId) state.team = await api(`organizations/${orgId}/members`);
    const team = state.team || {members:[],invitations:[],organization:{}};
    const org = team.organization || {};
    const pending = (team.invitations || []).filter(item => item.status === 'pending');
    state.view = 'team';
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page">${portalNav('team')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">${esc(org.name || 'ORGANIZATION')}</span><h1>Η ομάδα μου</h1><p>${number(org.used_seats)} ενεργές + ${number(org.reserved_seats)} δεσμευμένες από ${number(org.seat_limit)} θέσεις · ${number(org.creator_admins)} από ${number(org.creator_admin_limit)} Creator Admins.</p></div></header><div class="wpqs-team-layout"><section class="wpqs-list"><div class="section-heading"><div><h2>Μέλη</h2><p>Ρόλοι, κατάσταση λογαριασμού και τελευταία σύνδεση.</p></div><span>${number(org.available_seats)} διαθέσιμες θέσεις</span></div><div class="wpqs-table-scroll"><table class="wpqs-modern-table"><thead><tr><th>Χρήστης</th><th>Ρόλος</th><th>Κατάσταση</th><th>Εγγραφή</th><th>Ενέργειες</th></tr></thead><tbody>${(team.members || []).map(teamMemberRow).join('') || '<tr><td colspan="5" class="empty">Δεν υπάρχουν μέλη.</td></tr>'}</tbody></table></div></section><aside><section class="panel"><h2>Πρόσκληση χρηστών</h2><p>Ένα email ανά γραμμή. Επιτρέπονται μόνο τα εγκεκριμένα email domains του Organization ή εξαιρέσεις που ορίζει ο Super Admin.</p><textarea rows="7" data-invite-emails placeholder="creator@company.gr"></textarea><div class="wpqs-invite-tools"><input type="file" accept=".csv,text/csv" data-invite-csv><button data-invite-csv-load>Εισαγωγή CSV</button></div><label>Ρόλος<select data-invite-role><option value="creator">Quiz Creator</option><option value="viewer">Viewer</option><option value="creator_admin">Creator Admin</option></select></label><label>Λήξη πρόσκλησης<select data-invite-days><option value="3">3 ημέρες</option><option value="7" selected>7 ημέρες</option><option value="14">14 ημέρες</option><option value="30">30 ημέρες</option></select></label><button class="wpqs-primary" data-send-invitations>Αποστολή προσκλήσεων</button></section><section class="panel"><h3>Εκκρεμείς προσκλήσεις</h3>${pending.map(invite=>`<div class="wpqs-invite-row"><div><strong>${esc(invite.email)}</strong><small>${esc(orgRoleLabel(invite.org_role))} · λήξη ${esc(formatDate(invite.expires_at))}</small></div><div class="wpqs-invite-actions"><button type="button" data-resend-invite="${invite.id}">Επαναποστολή</button><button data-revoke-invite="${invite.id}">Ανάκληση</button></div></div>`).join('') || '<p class="empty">Δεν υπάρχουν εκκρεμείς προσκλήσεις.</p>'}</section></aside></div></main>`;
    bindPortalNav();
    root.querySelector('[data-invite-csv-load]').onclick = async () => {
      const file = root.querySelector('[data-invite-csv]').files?.[0];
      if (!file) return alert('Επιλέξτε ένα αρχείο CSV.');
      const text = await file.text();
      const emails = text.split(/\r?\n/).flatMap(line => line.split(/[;,]/)).map(value => value.trim().replace(/^"|"$/g,'')).filter(value => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value));
      root.querySelector('[data-invite-emails]').value = [...new Set(emails)].join('\n');
      toast(`${emails.length} emails φορτώθηκαν από το CSV`);
    };
    root.querySelector('[data-send-invitations]').onclick = async () => {
      const emails = root.querySelector('[data-invite-emails]').value;
      if (!emails.trim()) return alert('Προσθέστε τουλάχιστον ένα email.');
      try {
        const result = await api(`organizations/${orgId}/invitations`, {method:'POST',body:JSON.stringify({emails,org_role:root.querySelector('[data-invite-role]').value,expires_days:number(root.querySelector('[data-invite-days]').value,7)})});
        if (result.errors?.length) alert(result.errors.map(item=>`${item.email}: ${item.message}`).join('\n'));
        state.team = await api(`organizations/${orgId}/members`); state.dashboard = await api('dashboard'); renderTeam();
      } catch (error) { alert(error.message); }
    };
    root.querySelectorAll('[data-resend-invite]').forEach(button => button.onclick = async event => {
      event.preventDefault();
      event.stopPropagation();
      const invitationId = button.dataset.resendInvite;
      const originalText = button.textContent;
      button.disabled = true;
      button.textContent = 'Αποστολή…';
      try {
        const result = await api(`organizations/${orgId}/invitations/${invitationId}/resend`, {
          method:'POST',
          body:JSON.stringify({expires_days:7})
        });
        if (result.warning) alert(result.warning);
        else toast('Η πρόσκληση στάλθηκε ξανά');
        state.team = await api(`organizations/${orgId}/members`);
        renderTeam();
      } catch (error) {
        alert(error.message || 'Η επαναποστολή της πρόσκλησης απέτυχε.');
        button.disabled = false;
        button.textContent = originalText;
      }
    });
    root.querySelectorAll('[data-member-save]').forEach(button => button.onclick = async () => { const id=button.dataset.memberSave; await api(`organizations/${orgId}/members/${id}`,{method:'PUT',body:JSON.stringify({org_role:root.querySelector(`[data-member-role="${id}"]`).value,status:root.querySelector(`[data-member-status="${id}"]`).value})}); state.team=await api(`organizations/${orgId}/members`);renderTeam(); });
    root.querySelectorAll('[data-member-remove]').forEach(button => button.onclick = async () => { if(!confirm('Να αφαιρεθεί το μέλος; Τα quiz του δεν θα διαγραφούν.'))return; await api(`organizations/${orgId}/members/${button.dataset.memberRemove}`,{method:'DELETE',body:'{}'});state.team=await api(`organizations/${orgId}/members`);renderTeam(); });
    root.querySelectorAll('[data-revoke-invite]').forEach(button => button.onclick = async () => { await api(`organizations/${orgId}/invitations/${button.dataset.revokeInvite}`,{method:'DELETE'});state.team=await api(`organizations/${orgId}/members`);renderTeam(); });
  };

  const renderWorkspace = async () => {
    if (!WPQS.canManageTeam) return routePortal('dashboard');
    if (!state.workspace) state.workspace = await api('workspace');
    const org = state.workspace || {};
    const dashboard = org.dashboard || {};
    const domains = Array.isArray(org.domains) ? org.domains : [];
    const emailDomains = domains.filter(item => ['email','both'].includes(item.domain_type)).map(item => item.domain);
    const embedDomains = domains.filter(item => ['embed','both','custom'].includes(item.domain_type)).map(item => item.domain);
    const isSuper = bool(WPQS.context?.is_super_admin);
    const features = org.features || {};
    const branding = org.branding || {};
    const seatsUsed = number(org.used_seats) + number(org.reserved_seats);
    const seatsAvailable = Math.max(0, number(org.seat_limit) - seatsUsed);
    const seatPercent = Math.min(100, Math.round((seatsUsed / Math.max(1, number(org.seat_limit, 1))) * 100));
    const identityMarkup = isSuper
      ? `<div class="settings-grid two wpqs-workspace-admin-fields"><label>Όνομα Workspace<input data-workspace-field="name" value="${esc(org.name || '')}"></label><label>Slug<input data-workspace-field="slug" value="${esc(org.slug || '')}"></label><label>Όριο θέσεων<input type="number" min="1" data-workspace-field="seat_limit" value="${number(org.seat_limit,1)}"></label><label>Όριο Creator Admins<input type="number" min="1" data-workspace-field="creator_admin_limit" value="${number(org.creator_admin_limit,1)}"></label><label>Ημερομηνία λήξης<input type="datetime-local" data-workspace-field="expires_at" value="${esc(localDateTime(org.expires_at))}"></label><label>Κατάσταση<select data-workspace-field="status"><option value="active" ${org.status==='active'?'selected':''}>Active</option><option value="suspended" ${org.status==='suspended'?'selected':''}>Suspended</option><option value="expired" ${org.status==='expired'?'selected':''}>Expired</option></select></label></div>`
      : `<div class="wpqs-workspace-readonly" aria-label="Πληροφορίες Workspace"><div><span>Workspace</span><strong>${esc(org.name || '—')}</strong><small>${esc(org.slug || '')}</small></div><div><span>Διαθέσιμες θέσεις</span><strong>${seatsAvailable}</strong><small>${seatsUsed} από ${number(org.seat_limit)} χρησιμοποιούνται ή δεσμεύονται</small></div><div><span>Creator Admins</span><strong>${number(org.creator_admins)}</strong><small>από ${number(org.creator_admin_limit)} που έχει ορίσει ο Administrator</small></div><div><span>Πρόσβαση</span><strong>${esc(org.status || 'active')}</strong><small>${esc(org.expires_at ? `Λήξη ${formatDate(org.expires_at)}` : 'Χωρίς ημερομηνία λήξης')}</small></div></div><div class="wpqs-locked-notice"><span aria-hidden="true">🔒</span><div><strong>Τα όρια του Workspace είναι κλειδωμένα</strong><p>Οι θέσεις, το όριο Creator Admins, η λήξη, η κατάσταση και τα features αλλάζουν αποκλειστικά από WordPress Administrator.</p></div></div>`;
    state.view = 'workspace';
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page wpqs-workspace-page">${portalNav('workspace')}
      <header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">WORKSPACE CONTROL</span><h1>${esc(org.name || 'Το Workspace μου')}</h1><p>${isSuper ? 'Πλήρης διαχείριση ορίων, domains, branding και λειτουργιών του οργανισμού.' : 'Διαχειριστείτε τα εγκεκριμένα embed domains, το branding και την ομάδα σας. Τα όρια θέσεων ορίζονται μόνο από τον WordPress Administrator.'}</p></div><span class="wpqs-status-pill ${esc(org.status || 'active')}">${esc(org.status || 'active')}</span></header>
      <section class="wpqs-stat-grid wpqs-stat-grid--compact"><article><span>Χρήστες</span><b>${seatsUsed}</b><small>από ${number(org.seat_limit)} θέσεις</small></article><article><span>Creator Admins</span><b>${number(org.creator_admins)}</b><small>από ${number(org.creator_admin_limit)}</small></article><article><span>Quiz</span><b>${number(org.quiz_count || dashboard.total_quizzes)}</b><small>${number(dashboard.published_quizzes)} δημοσιευμένα</small></article><article><span>Διαθέσιμες</span><b>${seatsAvailable}</b><small>θέσεις ομάδας</small></article></section>
      <div class="wpqs-workspace-layout"><section class="panel wpqs-workspace-main"><div class="section-heading"><div><h2>Ταυτότητα Workspace</h2><p>${isSuper ? 'Βασικές πληροφορίες, όρια και κύκλος ζωής του οργανισμού.' : 'Συνοπτικές πληροφορίες του οργανισμού σας.'}</p></div></div>${identityMarkup}
        <div class="wpqs-workspace-section"><div class="section-heading"><div><h3>Whitelist domains για embeds</h3><p>Τα quiz του Workspace φορτώνουν μόνο στα παρακάτω εγκεκριμένα domains. Ένα domain ανά γραμμή, χωρίς πρωτόκολλο ή path.</p></div><span class="wpqs-security-badge">CSP protected</span></div><textarea rows="7" data-workspace-embed-domains placeholder="company.gr&#10;news.company.gr">${esc([...new Set(embedDomains)].join('\n'))}</textarea><div class="notice-inline"><strong>Email domains:</strong> ${esc([...new Set(emailDomains)].join(', ') || 'Δεν έχουν οριστεί')} ${isSuper ? '· Αλλάζουν από τα Organizations.' : '· Αλλάζουν μόνο από WordPress Administrator.'}</div></div>
        <div class="wpqs-workspace-section"><div class="section-heading"><div><h3>Branding</h3><p>Οπτική ταυτότητα που εφαρμόζεται στα quiz του Workspace.</p></div></div><div class="settings-grid two"><label>Logo URL<input data-workspace-branding="logo_url" value="${esc(branding.logo_url || '')}"></label><label>Favicon URL<input data-workspace-branding="favicon_url" value="${esc(branding.favicon_url || '')}"></label><label>Κύριο χρώμα<input type="color" data-workspace-branding="accent" value="${esc(branding.accent || '#c5a66a')}"></label><label>Δευτερεύον χρώμα<input type="color" data-workspace-branding="accent_secondary" value="${esc(branding.secondary || branding.accent_secondary || '#b7a5ff')}"></label><label class="wide">Footer quiz<input data-workspace-branding="footer_text" value="${esc(branding.footer_text || '')}"></label></div></div>
        <div class="wpqs-form-actions"><button class="wpqs-primary" data-workspace-save>${isSuper ? 'Αποθήκευση Workspace' : 'Αποθήκευση domains & branding'}</button>${isSuper?'<button class="wpqs-ghost" data-open-organizations>Πλήρεις ρυθμίσεις Organizations</button>':''}</div></section>
        <aside class="wpqs-workspace-side"><section class="panel"><h2>Ενεργές λειτουργίες</h2><div class="wpqs-feature-list">${Object.entries(features).map(([key,value])=>`<span class="${bool(value)?'is-enabled':'is-disabled'}"><i>${bool(value)?'✓':'–'}</i>${esc(key.replaceAll('_',' '))}</span>`).join('') || '<p class="empty">Δεν έχουν οριστεί περιορισμοί.</p>'}</div>${!isSuper ? '<p class="wpqs-panel-note">Οι λειτουργίες ενεργοποιούνται ή απενεργοποιούνται από WordPress Administrator.</p>' : ''}</section><section class="panel"><h2>Χρήση θέσεων</h2><div class="wpqs-seat-numbers"><strong>${seatsUsed}</strong><span>/ ${number(org.seat_limit)}</span></div><div class="wpqs-seat-progress"><i style="width:${seatPercent}%"></i></div><p>${number(org.used_seats)} ενεργές · ${number(org.reserved_seats)} δεσμευμένες · ${seatsAvailable} διαθέσιμες</p><button data-workspace-team>Διαχείριση ομάδας</button></section></aside></div></main>`;
    bindPortalNav();
    root.querySelector('[data-workspace-team]')?.addEventListener('click',()=>routePortal('team'));
    root.querySelector('[data-open-organizations]')?.addEventListener('click',()=>routePortal('organizations'));
    root.querySelector('[data-workspace-save]')?.addEventListener('click', async () => {
      const payload = {branding:{}};
      if (isSuper) {
        root.querySelectorAll('[data-workspace-field]').forEach(input => payload[input.dataset.workspaceField] = input.type === 'number' ? number(input.value) : input.value);
      }
      root.querySelectorAll('[data-workspace-branding]').forEach(input => payload.branding[input.dataset.workspaceBranding] = input.value);
      payload.embed_domains = root.querySelector('[data-workspace-embed-domains]').value.split(/[\r\n,;]+/).map(value=>value.trim()).filter(Boolean);
      if (isSuper) {
        payload.id = org.id;
        payload.features = features;
        payload.domains = [...domains.filter(item=>!['embed','custom'].includes(item.domain_type)), ...payload.embed_domains.map(domain=>({domain,domain_type:'embed',is_primary:false}))];
      }
      try { state.workspace = await api('workspace',{method:'PUT',body:JSON.stringify(payload)}); toast(isSuper ? 'Το Workspace αποθηκεύτηκε' : 'Τα domains και το branding αποθηκεύτηκαν'); renderWorkspace(); } catch(error) { alert(error.message); }
    });
  };

  const renderUserWorkspaces = async () => {
    if (!WPQS.canManageUserWorkspaces) return routePortal('dashboard');
    if (!state.userWorkspaces) state.userWorkspaces = await api('admin/user-workspaces');
    const data = state.userWorkspaces || {users:[],organizations:[]};
    const query = String(state.userWorkspaceQuery || '').toLocaleLowerCase('el');
    const users = (data.users || []).filter(user => !query || `${user.display_name} ${user.user_email} ${(user.wordpress_roles||[]).join(' ')} ${user.organization_name||''}`.toLocaleLowerCase('el').includes(query));
    state.view = 'user-workspaces';
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page wpqs-user-workspace-page">${portalNav('user-workspaces')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">WORDPRESS ADMIN ONLY</span><h1>Χρήστες & Workspaces</h1><p>Μεταφορά λογαριασμών μεταξύ Workspaces, ορισμός Organization role και προαιρετική μεταφορά των quiz τους.</p></div></header><section class="wpqs-list"><div class="wpqs-filterbar wpqs-filterbar--simple"><input data-user-workspace-search value="${esc(state.userWorkspaceQuery||'')}" placeholder="Αναζήτηση χρήστη, email ή Workspace"><span>${users.length} χρήστες</span></div><div class="wpqs-table-scroll"><table class="wpqs-modern-table wpqs-workspace-users-table"><thead><tr><th>Χρήστης</th><th>WordPress ρόλος</th><th>Workspace</th><th>Ρόλος Workspace</th><th>Κατάσταση</th><th>Quiz</th><th></th></tr></thead><tbody>${users.map(user=>`<tr><td><div class="wpqs-user-cell"><span>${esc((user.display_name||user.user_email).slice(0,1).toUpperCase())}</span><div><strong>${esc(user.display_name||user.user_login)}</strong><small>${esc(user.user_email)}</small>${user.is_wordpress_admin?'<span class="wpqs-protected-badge">WordPress Administrator</span>':''}</div></div></td><td>${esc((user.wordpress_roles||[]).join(', ')||'—')}</td><td><select data-user-workspace-org="${user.user_id}">${(data.organizations||[]).map(org=>`<option value="${org.id}" ${number(user.organization_id)===number(org.id)?'selected':''}>${esc(org.name)}</option>`).join('')}</select></td><td><select data-user-workspace-role="${user.user_id}"><option value="creator_admin" ${user.org_role==='creator_admin'?'selected':''}>Creator Admin</option><option value="creator" ${user.org_role==='creator'?'selected':''}>Quiz Creator</option><option value="viewer" ${user.org_role==='viewer'?'selected':''}>Viewer</option></select></td><td><select data-user-workspace-status="${user.user_id}"><option value="active" ${user.membership_status!=='suspended'?'selected':''}>Active</option><option value="suspended" ${user.membership_status==='suspended'?'selected':''}>Suspended</option></select></td><td><label class="wpqs-inline-check"><input type="checkbox" data-user-workspace-move-quizzes="${user.user_id}"><span>Μεταφορά quiz</span></label></td><td><button class="wpqs-primary wpqs-button-small" data-user-workspace-save="${user.user_id}">Ενημέρωση</button></td></tr>`).join('')||'<tr><td colspan="7" class="empty">Δεν βρέθηκαν χρήστες.</td></tr>'}</tbody></table></div></section></main>`;
    bindPortalNav();
    root.querySelector('[data-user-workspace-search]').oninput = event => { state.userWorkspaceQuery=event.target.value; renderUserWorkspaces(); root.querySelector('[data-user-workspace-search]')?.focus(); };
    root.querySelectorAll('[data-user-workspace-save]').forEach(button => button.onclick = async () => {
      const id=button.dataset.userWorkspaceSave;
      const payload={organization_id:number(root.querySelector(`[data-user-workspace-org="${id}"]`).value),org_role:root.querySelector(`[data-user-workspace-role="${id}"]`).value,status:root.querySelector(`[data-user-workspace-status="${id}"]`).value,move_quizzes:root.querySelector(`[data-user-workspace-move-quizzes="${id}"]`).checked};
      if (!confirm(payload.move_quizzes ? 'Να αλλάξει Workspace και να μεταφερθούν και τα quiz του χρήστη;' : 'Να αλλάξει το Workspace του χρήστη; Τα παλιά quiz θα παραμείνουν στο προηγούμενο Workspace.')) return;
      try { await api(`admin/users/${id}/workspace`,{method:'PUT',body:JSON.stringify(payload)}); state.userWorkspaces=await api('admin/user-workspaces'); state.team=null; state.workspace=null; toast('Το Workspace του χρήστη ενημερώθηκε'); renderUserWorkspaces(); } catch(error){ alert(error.message); }
    });
  };

  const defaultOrganization = () => ({name:'Νέος οργανισμός',slug:'',seat_limit:10,creator_admin_limit:1,status:'active',expires_at:null,domains:[{domain:'',domain_type:'both',is_primary:true}],features:{analytics:true,templates:true,personality:true,embeds:true,exports:true,invitations:true,white_label:false,approval_workflow:true},branding:{logo_id:0,logo_url:'',accent:'#d9bd85',accent_secondary:'#b9a7ff',footer_text:'',custom_domain:''}});

  const organizationEditorMarkup = org => `<section class="panel wpqs-org-editor"><h2>${org.id ? 'Επεξεργασία Organization' : 'Νέο Organization'}</h2><div class="settings-grid two"><label>Όνομα<input data-org-field="name" value="${esc(org.name)}"></label><label>Slug<input data-org-field="slug" value="${esc(org.slug || '')}"></label><label>Θέσεις<input type="number" min="1" data-org-field="seat_limit" value="${number(org.seat_limit,10)}"></label><label>Creator Admins<input type="number" min="1" data-org-field="creator_admin_limit" value="${number(org.creator_admin_limit,1)}"></label><label>Λήξη πρόσβασης<input type="datetime-local" data-org-field="expires_at" value="${esc(localDateTime(org.expires_at))}"></label><label>Κατάσταση<select data-org-field="status"><option value="active" ${org.status==='active'?'selected':''}>Active</option><option value="suspended" ${org.status==='suspended'?'selected':''}>Suspended</option><option value="expired" ${org.status==='expired'?'selected':''}>Expired</option></select></label></div><label>Email / Embed domains<textarea rows="5" data-org-domains placeholder="company.gr">${esc((org.domains || []).map(item=>item.domain).join('\n'))}</textarea></label><h3>Features</h3><div class="wpqs-feature-grid">${Object.entries(org.features || {}).map(([key,value])=>`<label><input type="checkbox" data-org-feature="${esc(key)}" ${bool(value)?'checked':''}> ${esc(key.replaceAll('_',' '))}</label>`).join('')}</div><h3>White-label branding</h3><div class="settings-grid two"><label>Accent<input type="color" data-org-branding="accent" value="${esc(org.branding?.accent || '#d9bd85')}"></label><label>Secondary<input type="color" data-org-branding="accent_secondary" value="${esc(org.branding?.accent_secondary || '#b9a7ff')}"></label><label>Logo URL<input data-org-branding="logo_url" value="${esc(org.branding?.logo_url || '')}"></label><label>Custom domain<input data-org-branding="custom_domain" value="${esc(org.branding?.custom_domain || '')}"></label><label class="wide">Quiz footer<input data-org-branding="footer_text" value="${esc(org.branding?.footer_text || '')}"></label></div><div class="wpqs-modal-actions"><button class="wpqs-primary" data-org-save>Αποθήκευση Organization</button><button data-org-cancel>Ακύρωση</button></div></section>`;

  const renderOrganizations = async () => {
    if (!WPQS.canManageOrganizations) return routePortal('dashboard');
    if (!state.organizations.length) state.organizations = await api('organizations');
    state.view = 'organizations';
    const editor = state.organizationEditor;
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page">${portalNav('organizations')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">SUPER ADMIN</span><h1>Organizations & Workspaces</h1><p>Domains, διαθέσιμες θέσεις, Creator Admins, λήξη πρόσβασης, features και white-label branding.</p></div><button class="wpqs-primary" data-org-new>+ Νέο Organization</button></header>${editor ? organizationEditorMarkup(editor) : `<section class="wpqs-org-grid">${state.organizations.map(org=>`<article class="wpqs-org-card"><div class="wpqs-org-card-head"><span class="status ${esc(org.status)}">${esc(org.status)}</span><b>${number(org.used_seats)}/${number(org.seat_limit)}</b></div><h2>${esc(org.name)}</h2><p>${esc((org.domains || []).map(d=>d.domain).join(', ') || org.slug)}</p><div class="wpqs-org-stats"><span><b>${number(org.quiz_count)}</b> quiz</span><span><b>${number(org.creator_admins)}</b> admins</span><span><b>${Math.max(0,number(org.seat_limit)-number(org.used_seats))}</b> θέσεις</span></div><button data-org-edit="${org.id}">Διαχείριση</button></article>`).join('')}</section>`}</main>`;
    bindPortalNav();
    root.querySelector('[data-org-new]')?.addEventListener('click',()=>{state.organizationEditor=defaultOrganization();renderOrganizations();});
    root.querySelectorAll('[data-org-edit]').forEach(button=>button.onclick=async()=>{state.organizationEditor=await api(`organizations/${button.dataset.orgEdit}`);renderOrganizations();});
    root.querySelector('[data-org-cancel]')?.addEventListener('click',()=>{state.organizationEditor=null;renderOrganizations();});
    root.querySelector('[data-org-save]')?.addEventListener('click',async()=>{
      const org=clone(state.organizationEditor);root.querySelectorAll('[data-org-field]').forEach(input=>{org[input.dataset.orgField]=input.type==='number'?number(input.value):input.value;});
      org.domains=root.querySelector('[data-org-domains]').value.split(/[\r\n,;]+/).filter(Boolean).map((domain,index)=>({domain,domain_type:'both',is_primary:index===0}));
      org.features={};root.querySelectorAll('[data-org-feature]').forEach(input=>org.features[input.dataset.orgFeature]=input.checked);
      org.branding=org.branding||{};root.querySelectorAll('[data-org-branding]').forEach(input=>org.branding[input.dataset.orgBranding]=input.value);
      await api(`organizations${org.id?'/'+org.id:''}`,{method:org.id?'PUT':'POST',body:JSON.stringify(org)});state.organizations=await api('organizations');state.organizationEditor=null;renderOrganizations();
    });
  };

  const documentationArticles = [
    {role:'creator',title:'Δημιουργία και αποθήκευση quiz',keywords:'creator quiz autosave questions',body:'Ανοίξτε τη Βιβλιοθήκη, πατήστε «Νέο Quiz», προσθέστε ερωτήσεις και επιλέξτε ορατότητα Private ή Organization. Η αυτόματη αποθήκευση εκτελείται ανά λίγα δευτερόλεπτα και οι χειροκίνητες αποθηκεύσεις δημιουργούν revisions.'},
    {role:'creator',title:'Υποβολή για έγκριση',keywords:'review workflow creator',body:'Από την καρτέλα Workflow γράψτε προαιρετικό σχόλιο και πατήστε «Υποβολή για έλεγχο». Μετά την υποβολή, ο Creator Admin μπορεί να εγκρίνει ή να ζητήσει αλλαγές.'},
    {role:'creator_admin',title:'Ομάδα, προσκλήσεις και θέσεις',keywords:'team seats invitations csv admin',body:'Στη σελίδα «Η ομάδα μου» βλέπετε ενεργούς χρήστες, διαθέσιμες θέσεις και εκκρεμείς προσκλήσεις. Μπορείτε να στείλετε μία ή πολλές προσκλήσεις ή να εισαγάγετε emails από CSV.'},
    {role:'creator_admin',title:'Έγκριση και δημοσίευση',keywords:'approval approve changes publish',body:'Στην καρτέλα Workflow ενός quiz μπορείτε να αφήσετε σχόλιο, να ζητήσετε αλλαγές, να εγκρίνετε ή να δημοσιεύσετε. Κάθε ενέργεια καταγράφεται στο Activity Log και αποστέλλεται email.'},
    {role:'administrator',title:'Organizations και domains',keywords:'organizations domains seats super admin',body:'Ο WordPress Administrator δημιουργεί Organizations, ορίζει domains, θέσεις, όριο Creator Admins, λήξη πρόσβασης, features, κατάσταση και white-label branding.'},
    {role:'administrator',title:'Ορατότητα Private / Organization / Universal',keywords:'visibility private organization universal',body:'Private βλέπει ο δημιουργός και οι admins του Organization. Organization βλέπουν όλοι οι εγκεκριμένοι χρήστες του ίδιου workspace. Universal βλέπουν όλοι οι εγκεκριμένοι χρήστες, αλλά το αρχικό περιεχόμενο το διαχειρίζεται μόνο ο Super Admin ή Universal Manager.'},
    {role:'all',title:'Ενσωμάτωση σε WordPress, Drupal ή άλλο CMS',keywords:'embed iframe javascript drupal wordpress gutenberg',body:'Ανοίξτε «Ενσωμάτωση» από την κάρτα του quiz. Για Gutenberg χρησιμοποιήστε block Shortcode ή Custom HTML. Για Drupal/Joomla/Laravel χρησιμοποιήστε το iframe ή το JavaScript embed. Η whitelist domains ελέγχει πού επιτρέπεται η φόρτωση.'},
    {role:'all',title:'Analytics και εξαγωγές',keywords:'analytics csv pdf reports',body:'Τα Analytics περιλαμβάνουν views, starts, completions, completion rate, score, χρόνο, funnel, drop-off ανά ερώτηση, συσκευές, referrers και UTM. Η εξαγωγή CSV είναι άμεση και το κουμπί Εκτύπωση / PDF ανοίγει print-friendly report.'},
    {role:'all',title:'Προσβασιμότητα',keywords:'accessibility keyboard wcag',body:'Χρησιμοποιήστε Tab και Shift+Tab για πλοήγηση, Enter ή Space για ενεργοποίηση controls και Escape για κλείσιμο popup. Τα presets ελέγχουν αντίθεση και υποστηρίζεται reduced motion.'}
  ];

  const renderSystemStatus = async () => {
    if (!WPQS.canManageUserWorkspaces) return routePortal('dashboard');
    state.view = 'system';
    if (!state.systemHealth) {
      root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page wpqs-system-page">${portalNav('system')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">RELEASE HEALTH</span><h1>System Status</h1><p>Έλεγχος εγκατάστασης, βάσης, cron, embeds και περιβάλλοντος.</p></div></header><section class="wpqs-list"><div class="empty-panel">Εκτελείται έλεγχος συστήματος…</div></section></main>`;
      bindPortalNav();
      try { state.systemHealth = await api('system/health'); }
      catch (error) { root.querySelector('.empty-panel').textContent = error.message; return; }
    }
    const report = state.systemHealth || {};
    const counts = report.counts || {};
    const checks = Array.isArray(report.checks) ? report.checks : [];
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page wpqs-system-page">${portalNav('system')}
      <header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">QUIZ ATELIER ${esc(report.version || WPQS.version || '')}</span><h1>System Status</h1><p>Έλεγχος βάσης, cron, REST API, uploads, permalinks και απαιτήσεων server.</p></div><div class="wpqs-header-actions"><button type="button" class="wpqs-ghost" data-system-refresh>Νέος έλεγχος</button><button type="button" class="wpqs-primary" data-system-repair>Επιδιόρθωση εγκατάστασης</button></div></header>
      <section class="wpqs-cards wpqs-summary-cards wpqs-system-counts"><article><span>Organizations</span><b>${number(counts.organizations)}</b><small>${number(counts.members)} μέλη</small></article><article><span>Quiz</span><b>${number(counts.quizzes)}</b><small>${number(counts.questions)} ερωτήσεις</small></article><article><span>Αποτελέσματα</span><b>${number(counts.results)}</b><small>ολοκληρώσεις</small></article><article><span>Analytics events</span><b>${number(counts.analytics_events)}</b><small>καταγραφές</small></article></section>
      <section class="wpqs-system-layout"><div class="wpqs-list"><div class="section-heading"><div><h2>Έλεγχοι εγκατάστασης</h2><p>Τα errors χρειάζονται άμεση διόρθωση. Τα warnings είναι συστάσεις.</p></div><span class="wpqs-health-overall is-${esc(report.status || 'warning')}">${report.status === 'ok' ? 'Όλα σωστά' : report.status === 'error' ? 'Χρειάζεται διόρθωση' : 'Υπάρχουν προειδοποιήσεις'}</span></div><div class="wpqs-health-list">${checks.map(check => `<article class="wpqs-health-row is-${esc(check.status)}"><span class="wpqs-health-icon">${check.status === 'ok' ? '✓' : check.status === 'error' ? '×' : '!'}</span><div><strong>${esc(check.label)}</strong><small>${esc(check.detail)}</small></div><b>${check.status === 'ok' ? 'OK' : check.status === 'error' ? 'ERROR' : 'WARNING'}</b></article>`).join('')}</div></div>
      <aside class="panel wpqs-environment-card"><h2>Περιβάλλον</h2><dl>${Object.entries(report.environment || {}).map(([key,value]) => `<dt>${esc(key.replaceAll('_',' '))}</dt><dd>${esc(String(value))}</dd>`).join('')}</dl><p>Τελευταίος έλεγχος: ${esc(formatDate(report.generated_at))}</p><div class="notice-inline">Η επιδιόρθωση επανεκτελεί τις migrations, επαναφέρει το WP-Cron event και ανανεώνει τα rewrite rules χωρίς να διαγράφει δεδομένα.</div></aside></section>
    </main>`;
    bindPortalNav();
    root.querySelector('[data-system-refresh]').onclick = async () => { state.systemHealth = null; await renderSystemStatus(); };
    root.querySelector('[data-system-repair]').onclick = async event => {
      const button = event.currentTarget; button.disabled = true; button.textContent = 'Επιδιόρθωση…';
      try { state.systemHealth = await api('system/repair', {method:'POST', body:'{}'}); toast('Η εγκατάσταση ελέγχθηκε και επιδιορθώθηκε'); renderSystemStatus(); }
      catch (error) { button.disabled = false; button.textContent = 'Επιδιόρθωση εγκατάστασης'; toast(error.message); }
    };
  };

  const renderDocumentation = () => {
    state.view = 'documentation';
    const role = state.docsRole || 'all';
    const query = String(state.docsQuery || '').trim().toLocaleLowerCase('el');
    const rows = documentationArticles.filter(article => (role === 'all' || article.role === 'all' || article.role === role) && (!query || `${article.title} ${article.keywords} ${article.body}`.toLocaleLowerCase('el').includes(query)));
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page">${portalNav('documentation')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">HELP CENTER</span><h1>Τεκμηρίωση Quiz Atelier</h1><p>Οδηγίες ανά ρόλο για δημιουργία, έγκριση, Organizations, embeds και analytics.</p></div><button class="wpqs-ghost" data-doc-print>Εκτύπωση οδηγών</button></header><section class="wpqs-list wpqs-docs-layout"><div class="wpqs-docs-toolbar"><input data-doc-search value="${esc(state.docsQuery || '')}" placeholder="Αναζήτηση στην τεκμηρίωση"><select data-doc-role><option value="all" ${role==='all'?'selected':''}>Όλοι οι ρόλοι</option><option value="creator" ${role==='creator'?'selected':''}>Quiz Creator</option><option value="creator_admin" ${role==='creator_admin'?'selected':''}>Creator Admin</option><option value="administrator" ${role==='administrator'?'selected':''}>Administrator</option></select></div><div class="wpqs-doc-grid">${rows.map((article,index)=>`<article class="wpqs-doc-card"><span class="wpqs-kicker">${esc(article.role === 'all' ? 'ΟΛΟΙ' : article.role.replace('_',' ').toUpperCase())}</span><h2>${esc(article.title)}</h2><p>${esc(article.body)}</p><small>${index+1} / ${rows.length}</small></article>`).join('') || '<div class="empty-panel">Δεν βρέθηκε σχετική οδηγία.</div>'}</div><div class="notice-inline"><strong>Shortcodes:</strong> <code>[wp_quiz_studio_builder]</code> για το Creator Portal, <code>[wp_quiz_studio id="25"]</code> για ένα quiz και <code>[wp_quiz_studio_directory]</code> για δημόσιο κατάλογο.</div></section></main>`;
    bindPortalNav();
    root.querySelector('[data-doc-search]').oninput = event => { state.docsQuery = event.target.value; renderDocumentation(); root.querySelector('[data-doc-search]')?.focus(); };
    root.querySelector('[data-doc-role]').onchange = event => { state.docsRole = event.target.value; renderDocumentation(); };
    root.querySelector('[data-doc-print]').onclick = () => window.print();
  };

  const renderProfile = () => {
    const me = state.me || {}; const ctx = me.context || {}; const org = me.organization || {}; const prefs = me.email_preferences || {};
    state.view = 'profile';
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page">${portalNav('profile')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">ACCOUNT</span><h1>Ο λογαριασμός μου</h1><p>Προσωπικά στοιχεία, email, password, γλώσσα και προτιμήσεις ειδοποιήσεων.</p></div></header><div class="wpqs-profile-layout"><section class="panel wpqs-profile-card"><img src="${esc(me.avatar_url||'')}" alt="Avatar"><h2>${esc(me.display_name||'')}</h2><p>${esc(me.email||'')}</p><dl><dt>Ρόλος</dt><dd>${esc(orgRoleLabel(ctx.organization_role||me.roles?.[0]||''))}</dd><dt>Organization</dt><dd>${esc(org.name||'—')}</dd><dt>Εγγραφή</dt><dd>${esc(formatDate(me.registered_at))}</dd><dt>Τελευταία σύνδεση</dt><dd>${esc(formatDate(me.last_login))}</dd><dt>Κατάσταση</dt><dd>${esc(ctx.membership?.status || 'active')}</dd></dl></section><section class="panel"><div class="settings-grid two"><label>Όνομα εμφάνισης<input data-profile-field="display_name" value="${esc(me.display_name||'')}"></label><label>Email<input type="email" data-profile-field="email" value="${esc(me.email||'')}"></label><label>Website<input type="url" data-profile-field="website" value="${esc(me.website||'')}"></label><label>Νέος κωδικός<input type="password" data-profile-field="password" autocomplete="new-password" minlength="8"></label><label>Γλώσσα<select data-profile-field="language"><option value="el" ${me.language !== 'en' ? 'selected' : ''}>Ελληνικά</option><option value="en" ${me.language === 'en' ? 'selected' : ''}>English</option></select></label></div><h3>Email preferences</h3><div class="wpqs-feature-grid"><label><input type="checkbox" data-email-pref="workflow" ${prefs.workflow !== false ? 'checked' : ''}> Workflow notifications</label><label><input type="checkbox" data-email-pref="analytics" ${prefs.analytics !== false ? 'checked' : ''}> Weekly analytics report</label><label><input type="checkbox" data-email-pref="security" ${prefs.security !== false ? 'checked' : ''}> New login alerts</label></div><button class="wpqs-primary" data-profile-save>Αποθήκευση αλλαγών</button></section></div></main>`;
    bindPortalNav();
    root.querySelector('[data-profile-save]').onclick = async () => {
      const data = {};
      root.querySelectorAll('[data-profile-field]').forEach(input => data[input.dataset.profileField] = input.value);
      data.email_preferences = {};
      root.querySelectorAll('[data-email-pref]').forEach(input => data.email_preferences[input.dataset.emailPref] = input.checked);
      try { state.me = await api('profile',{method:'PUT',body:JSON.stringify(data)}); toast('Το προφίλ ενημερώθηκε'); renderProfile(); }
      catch (error) { alert(error.message); }
    };
  };

  const quickUpdateQuiz = async (id, changes, control) => {
    if (control) control.disabled = true;
    try {
      const updated = normaliseQuiz(await api(`quizzes/${id}/quick-update`, {method: 'PUT', body: JSON.stringify(changes)}));
      state.quizzes = state.quizzes.map(item => number(item.id) === number(id) ? {...item, ...updated} : item);
      toast('Το quiz ενημερώθηκε');
      renderList();
    } catch (error) {
      if (control) control.disabled = false;
      alert(error.message);
      renderList();
    }
  };

  const applyListFilters = () => {
    const query = state.listQuery.trim().toLocaleLowerCase('el');
    let visible = 0;
    root.querySelectorAll('[data-quiz-item]').forEach(item => {
      const matches = (!query || item.dataset.search.includes(query))
        && (state.listStatus === 'all' || item.dataset.status === state.listStatus)
        && (state.listType === 'all' || item.dataset.type === state.listType)
        && (state.listCategory === 'all' || item.dataset.category === state.listCategory)
        && (state.listScope === 'all' || item.dataset.scope === state.listScope)
        && (state.listWorkflow === 'all' || item.dataset.workflow === state.listWorkflow)
        && (state.listCreator === 'all' || item.dataset.creator === state.listCreator);
      item.hidden = !matches;
      if (matches) visible++;
    });
    const counter = root.querySelector('[data-visible-count]');
    if (counter) counter.textContent = `${visible} από ${state.quizzes.length} quiz`;
    const empty = root.querySelector('[data-filter-empty]');
    if (empty) empty.hidden = visible > 0;
  };

  const renderList = () => {
    state.view = 'list'; state.portalView = 'library';
    const completions = state.dashboardAnalytics?.overview?.completions ?? state.dashboardAnalytics?.completions ?? state.quizzes.reduce((sum, quiz) => sum + number(quiz.completions), 0);
    const views = state.dashboardAnalytics?.overview?.views ?? state.dashboardAnalytics?.views ?? state.quizzes.reduce((sum, quiz) => sum + number(quiz.views), 0);
    const adminTransferActions = WPQS.isFront ? '' : '<button class="wpqs-ghost" data-import>Εισαγωγή JSON</button><input type="file" data-import-file accept="application/json,.json" hidden>';
    const categoryFilterOptions = state.categories.map(category => `<option value="${category.id}" ${String(state.listCategory) === String(category.id) ? 'selected' : ''}>${esc(category.name)}</option>`).join('');
    const statusOptions = status => `<option value="draft" ${status === 'draft' ? 'selected' : ''}>Πρόχειρο</option><option value="published" ${status === 'published' ? 'selected' : ''}>Δημοσιευμένο</option><option value="scheduled" ${status === 'scheduled' ? 'selected' : ''}>Προγραμματισμένο</option><option value="private" ${status === 'private' ? 'selected' : ''}>Ιδιωτικό</option><option value="expired" ${status === 'expired' ? 'selected' : ''}>Έληξε</option>`;
    const typeOptions = type => quizTypes.map(value => `<option value="${value}" ${type === value ? 'selected' : ''}>${esc(quizTypeLabel(value))}</option>`).join('');
    const visibilityOptions = scope => `<option value="personal" ${scope === 'personal' ? 'selected' : ''}>Private</option><option value="organization" ${scope === 'organization' ? 'selected' : ''}>Organization</option>${WPQS.canManageUniversal ? `<option value="universal" ${scope === 'universal' ? 'selected' : ''}>Universal</option>` : scope === 'universal' ? '<option value="universal" selected>Universal</option>' : ''}`;
    const workflowOptions = quiz => {
      const current = quiz.workflow_status || 'draft';
      let values = ['draft','submitted','changes_requested','approved','published','archived'];
      if (!WPQS.canReview) values = number(quiz.author_id) === number(state.me?.id) ? ['draft','submitted'] : [current];
      if (!values.includes(current)) values.unshift(current);
      return [...new Set(values)].map(value => `<option value="${value}" ${current === value ? 'selected' : ''}>${esc(workflowLabel(value))}</option>`).join('');
    };
    const sortedQuizzes = [...state.quizzes].sort((a,b) => {
      if (state.listSort === 'title_asc') return String(a.title||'').localeCompare(String(b.title||''), 'el');
      if (state.listSort === 'views_desc') return number(b.views)-number(a.views);
      if (state.listSort === 'completions_desc') return number(b.completions)-number(a.completions);
      if (state.listSort === 'created_desc') return String(b.created_at||'').localeCompare(String(a.created_at||''));
      return String(b.updated_at||'').localeCompare(String(a.updated_at||''));
    });
    const itemMarkup = quiz => {
      const cover = getQuizCover(quiz);
      const categoryName = quiz.category?.name || quiz.settings.category || 'Χωρίς κατηγορία';
      const search = `${quiz.title} ${categoryName} ${quizTypeLabel(quiz.quiz_type)} ${statusLabel(quiz.status)} ${visibilityLabel(quiz.visibility_scope)} ${workflowLabel(quiz.workflow_status)} ${quiz.author_name || ''}`.toLocaleLowerCase('el');
      if (state.listView === 'table') {
        return `<tr data-quiz-item data-search="${esc(search)}" data-status="${esc(quiz.status)}" data-type="${esc(quiz.quiz_type)}" data-category="${esc(String(quiz.category_id || 0))}" data-scope="${esc(quiz.visibility_scope)}" data-workflow="${esc(quiz.workflow_status)}" data-creator="${esc(String(quiz.author_id || 0))}">
          <td><div class="wpqs-table-title">${cover ? `<img src="${esc(cover)}" alt="">` : '<span class="wpqs-cover-placeholder">Q</span>'}<div><strong>${esc(quiz.title)}</strong><small>#${quiz.id} · ${esc(categoryName)} · ${esc(quiz.author_name || 'Άγνωστος creator')}</small></div></div></td>
          <td><select class="wpqs-inline-select" data-quick-type="${quiz.id}">${typeOptions(quiz.quiz_type)}</select></td>
          <td><select class="wpqs-inline-select" data-quick-scope="${quiz.id}">${visibilityOptions(quiz.visibility_scope)}</select></td>
          <td><select class="wpqs-inline-select" data-quick-workflow="${quiz.id}" ${!WPQS.canReview && quiz.author_id !== state.me?.id ? 'disabled' : ''}>${workflowOptions(quiz)}</select></td>
          <td><select class="wpqs-inline-select status-select ${esc(quiz.status)}" data-quick-status="${quiz.id}">${statusOptions(quiz.status)}</select></td>
          <td>${number(quiz.views)}</td><td>${number(quiz.completions)}</td><td>${esc(formatDate(quiz.updated_at))}</td>
          <td class="wpqs-actions"><button class="wpqs-link" data-edit="${quiz.id}">Επεξεργασία</button><button class="wpqs-link" data-preview-list="${quiz.id}">Preview</button><button class="wpqs-link" data-embed="${quiz.id}">Embed</button>${WPQS.canAnalytics ? `<button class="wpqs-link" data-analytics="${quiz.id}">Στατιστικά</button>` : ''}<button class="wpqs-link" data-duplicate="${quiz.id}">Αντιγραφή</button>${WPQS.isFront ? '' : `<button class="wpqs-link" data-export="${quiz.id}">Εξαγωγή</button>`}${WPQS.canDelete ? `<button class="wpqs-link danger" data-delete="${quiz.id}">Διαγραφή</button>` : ''}</td>
        </tr>`;
      }
      return `<article class="wpqs-quiz-card" data-quiz-item data-search="${esc(search)}" data-status="${esc(quiz.status)}" data-type="${esc(quiz.quiz_type)}" data-category="${esc(String(quiz.category_id || 0))}" data-scope="${esc(quiz.visibility_scope)}" data-workflow="${esc(quiz.workflow_status)}" data-creator="${esc(String(quiz.author_id || 0))}">
        <div class="wpqs-quiz-cover">${cover ? `<img src="${esc(cover)}" alt="">` : '<div class="wpqs-cover-art"><span>ATELIER</span><b>Q</b></div>'}<span class="status ${esc(quiz.status)}">${esc(statusLabel(quiz.status))}</span></div>
        <div class="wpqs-quiz-card-body"><div class="wpqs-quiz-meta"><span>${esc(quizTypeLabel(quiz.quiz_type))}</span><span class="wpqs-scope-badge ${esc(quiz.visibility_scope)}">${esc(visibilityLabel(quiz.visibility_scope))}</span><span>${esc(categoryName)}</span></div><h3>${esc(quiz.title)}</h3><p>${esc(quiz.description || 'Χωρίς περιγραφή')}</p>
          <div class="wpqs-card-controls"><label>Τύπος<select data-quick-type="${quiz.id}">${typeOptions(quiz.quiz_type)}</select></label><label>Ορατότητα<select data-quick-scope="${quiz.id}">${visibilityOptions(quiz.visibility_scope)}</select></label><label>Workflow<select data-quick-workflow="${quiz.id}">${workflowOptions(quiz)}</select></label><label>Status<select data-quick-status="${quiz.id}">${statusOptions(quiz.status)}</select></label></div>
          <div class="wpqs-card-stats"><span><b>${number(quiz.views)}</b> προβολές</span><span><b>${number(quiz.completions)}</b> ολοκληρώσεις</span><span>${esc(quiz.author_name || 'Creator')} · ${esc(workflowLabel(quiz.workflow_status))}</span><span>Ενημέρωση ${esc(formatDate(quiz.updated_at))}</span></div>
          <div class="wpqs-card-actions"><button class="wpqs-primary" data-edit="${quiz.id}">Επεξεργασία</button><button data-preview-list="${quiz.id}">Προεπισκόπηση ↗</button><button data-embed="${quiz.id}">Ενσωμάτωση</button>${WPQS.canAnalytics ? `<button data-analytics="${quiz.id}">Στατιστικά</button>` : ''}<details><summary>•••</summary><div><button data-duplicate="${quiz.id}">Αντιγραφή</button>${WPQS.isFront ? '' : `<button data-export="${quiz.id}">Εξαγωγή JSON</button>`}${WPQS.canDelete ? `<button class="danger" data-delete="${quiz.id}">Διαγραφή</button>` : ''}</div></details></div>
        </div>
      </article>`;
    };

    root.innerHTML = `<main class="wpqs-dashboard wpqs-dashboard-v2 wpqs-portal-page">${portalNav('library')}
      <header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">QUIZ ATELIER ${esc(WPQS.version || '1.0.0')}</span><h1>Quiz Library</h1><p>Τα δικά σας quiz, τα κοινόχρηστα του Organization και τα Universal templates/quiz.</p></div><div class="wpqs-header-actions"><button class="wpqs-ghost" data-user-style-open>Το στυλ μου</button><button class="wpqs-ghost" data-open-categories>Κατηγορίες</button>${adminTransferActions}${WPQS.canAnalytics ? '<button class="wpqs-ghost" data-global-analytics>Συνολικά Analytics</button>' : ''}<button class="wpqs-primary" data-new>+ Νέο quiz</button></div></header>
      <section class="wpqs-cards wpqs-summary-cards"><article><span>Σύνολο</span><b>${state.quizzes.length}</b><small>quiz</small></article><article><span>Δημοσιευμένα</span><b>${state.quizzes.filter(q => q.status === 'published').length}</b><small>ενεργά</small></article><article><span>Προβολές</span><b>${views}</b><small>συνολικά</small></article><article><span>Ολοκληρώσεις</span><b>${completions}</b><small>συνολικά</small></article></section>
      <section class="wpqs-list wpqs-library"><div class="wpqs-library-head"><div><h2>Βιβλιοθήκη quiz</h2><span data-visible-count>${state.quizzes.length} από ${state.quizzes.length} quiz</span></div><div class="wpqs-view-toggle"><button class="${state.listView === 'grid' ? 'is-active' : ''}" data-list-view="grid" title="Κάρτες">▦</button><button class="${state.listView === 'table' ? 'is-active' : ''}" data-list-view="table" title="Πίνακας">☷</button></div></div>
        <div class="wpqs-list-toolbar"><label class="wpqs-search"><span>⌕</span><input value="${esc(state.listQuery)}" data-list-search placeholder="Αναζήτηση τίτλου, κατηγορίας ή τύπου"></label><select data-list-status><option value="all">Όλες οι καταστάσεις</option>${['draft','published','scheduled','private','expired'].map(value => `<option value="${value}" ${state.listStatus === value ? 'selected' : ''}>${esc(statusLabel(value))}</option>`).join('')}</select><select data-list-type><option value="all">Όλοι οι τύποι</option>${quizTypes.map(value => `<option value="${value}" ${state.listType === value ? 'selected' : ''}>${esc(quizTypeLabel(value))}</option>`).join('')}</select><select data-list-category><option value="all">Όλες οι κατηγορίες</option><option value="0" ${String(state.listCategory) === '0' ? 'selected' : ''}>Χωρίς κατηγορία</option>${categoryFilterOptions}</select><select data-list-scope><option value="all">Όλες οι ορατότητες</option><option value="personal" ${state.listScope==='personal'?'selected':''}>Private</option><option value="organization" ${state.listScope==='organization'?'selected':''}>Organization</option><option value="universal" ${state.listScope==='universal'?'selected':''}>Universal</option></select><select data-list-workflow><option value="all">Όλο το workflow</option>${['draft','submitted','changes_requested','approved','published','archived'].map(value=>`<option value="${value}" ${state.listWorkflow===value?'selected':''}>${esc(workflowLabel(value))}</option>`).join('')}</select>${WPQS.canManageTeam || WPQS.canManageOrganizations ? `<select data-list-creator><option value="all">Όλοι οι creators</option>${[...new Map(state.quizzes.filter(q=>q.author_id).map(q=>[q.author_id,q.author_name||`User #${q.author_id}`])).entries()].map(([id,name])=>`<option value="${id}" ${String(state.listCreator)===String(id)?'selected':''}>${esc(name)}</option>`).join('')}</select>` : ''}<select data-list-sort aria-label="Ταξινόμηση"><option value="updated_desc" ${state.listSort==='updated_desc'?'selected':''}>Πρόσφατα ενημερωμένα</option><option value="created_desc" ${state.listSort==='created_desc'?'selected':''}>Νεότερα</option><option value="views_desc" ${state.listSort==='views_desc'?'selected':''}>Περισσότερες προβολές</option><option value="completions_desc" ${state.listSort==='completions_desc'?'selected':''}>Περισσότερες ολοκληρώσεις</option><option value="title_asc" ${state.listSort==='title_asc'?'selected':''}>Τίτλος Α–Ω</option></select></div>
        ${state.listView === 'table' ? `<div class="wpqs-table-scroll"><table class="wpqs-modern-table"><thead><tr><th>Quiz</th><th>Τύπος</th><th>Ορατότητα</th><th>Workflow</th><th>Κατάσταση</th><th>Προβολές</th><th>Ολοκληρώσεις</th><th>Ενημέρωση</th><th>Ενέργειες</th></tr></thead><tbody>${sortedQuizzes.map(itemMarkup).join('')}</tbody></table></div>` : `<div class="wpqs-quiz-grid">${sortedQuizzes.map(itemMarkup).join('')}</div>`}
        <div class="empty-panel" data-filter-empty hidden>Δεν βρέθηκαν quiz με αυτά τα φίλτρα.</div>
      </section>
    </main>`;

    bindPortalNav();
    root.querySelector('[data-new]').onclick = () => openQuiz(emptyQuiz());
    root.querySelector('[data-user-style-open]')?.addEventListener('click', openUserStyleModal);
    root.querySelector('[data-open-categories]')?.addEventListener('click', renderCategoriesPage);
    root.querySelector('[data-global-analytics]')?.addEventListener('click', openGlobalAnalytics);
    root.querySelector('[data-import]')?.addEventListener('click', () => root.querySelector('[data-import-file]')?.click());
    const importInput = root.querySelector('[data-import-file]');
    if (importInput) importInput.onchange = async event => {
      const file = event.target.files?.[0]; if (!file) return;
      try { const payload = JSON.parse(await file.text()); const imported = await api('quizzes/import', {method: 'POST', body: JSON.stringify(payload)}); toast('Το quiz εισήχθη ως πρόχειρο'); openQuiz(imported); }
      catch (error) { alert(error.message || 'Το αρχείο JSON δεν είναι έγκυρο.'); }
      finally { event.target.value = ''; }
    };
    root.querySelector('[data-list-search]').oninput = event => { state.listQuery = event.target.value.toLocaleLowerCase('el'); applyListFilters(); };
    root.querySelector('[data-list-status]').onchange = event => { state.listStatus = event.target.value; applyListFilters(); };
    root.querySelector('[data-list-type]').onchange = event => { state.listType = event.target.value; applyListFilters(); };
    root.querySelector('[data-list-category]').onchange = event => { state.listCategory = event.target.value; applyListFilters(); };
    root.querySelector('[data-list-scope]').onchange = event => { state.listScope = event.target.value; applyListFilters(); };
    root.querySelector('[data-list-workflow]').onchange = event => { state.listWorkflow = event.target.value; applyListFilters(); };
    root.querySelector('[data-list-creator]')?.addEventListener('change', event => { state.listCreator = event.target.value; applyListFilters(); });
    root.querySelector('[data-list-sort]')?.addEventListener('change', event => { state.listSort = event.target.value; localStorage.setItem('wpqs_list_sort', state.listSort); renderList(); });
    root.querySelectorAll('[data-list-view]').forEach(button => button.onclick = () => { state.listView = button.dataset.listView; localStorage.setItem('wpqs_list_view', state.listView); renderList(); });
    root.querySelectorAll('[data-edit]').forEach(button => button.onclick = async () => openQuiz(await api(`quizzes/${button.dataset.edit}`)));
    root.querySelectorAll('[data-preview-list]').forEach(button => button.onclick = () => window.open(`${WPQS.site}wpqs-embed/${button.dataset.previewList}/`, '_blank', 'noopener'));
    root.querySelectorAll('[data-analytics]').forEach(button => button.onclick = async () => { const quiz = await api(`quizzes/${button.dataset.analytics}`); openQuiz(quiz, 'analytics'); });
    root.querySelectorAll('[data-duplicate]').forEach(button => button.onclick = async () => { try { const duplicate = await api(`quizzes/${button.dataset.duplicate}/duplicate`, {method: 'POST', body: '{}'}); toast('Το quiz αντιγράφηκε'); openQuiz(duplicate); } catch (error) { alert(error.message); } });
    root.querySelectorAll('[data-export]').forEach(button => button.onclick = async () => { try { const payload = await api(`quizzes/${button.dataset.export}/export`); const slug = String(payload?.quiz?.slug || `quiz-${button.dataset.export}`).replace(/[^a-z0-9-_]+/gi, '-'); const blob = new Blob([JSON.stringify(payload, null, 2)], {type: 'application/json'}); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = `${slug}.wpqs.json`; document.body.appendChild(link); link.click(); URL.revokeObjectURL(link.href); link.remove(); } catch (error) { alert(error.message); } });
    root.querySelectorAll('[data-embed]').forEach(button => button.onclick = () => { const quiz = state.quizzes.find(item => number(item.id) === number(button.dataset.embed)); if (quiz) openEmbedModal(quiz); });
    root.querySelectorAll('[data-quick-status]').forEach(select => select.onchange = event => quickUpdateQuiz(select.dataset.quickStatus, {status: event.target.value}, select));
    root.querySelectorAll('[data-quick-type]').forEach(select => select.onchange = event => quickUpdateQuiz(select.dataset.quickType, {quiz_type: event.target.value}, select));
    root.querySelectorAll('[data-quick-scope]').forEach(select => select.onchange = event => quickUpdateQuiz(select.dataset.quickScope, {visibility_scope: event.target.value}, select));
    root.querySelectorAll('[data-quick-workflow]').forEach(select => select.onchange = event => quickUpdateQuiz(select.dataset.quickWorkflow, {workflow_status: event.target.value}, select));
    root.querySelectorAll('[data-delete]').forEach(button => button.onclick = async () => { if (!confirm('Να διαγραφεί το quiz μαζί με τα αποτελέσματα, τα στατιστικά και τις εκδόσεις του;')) return; try { await api(`quizzes/${button.dataset.delete}`, {method: 'DELETE'}); toast('Το quiz διαγράφηκε'); load(); } catch (error) { alert(error.message); } });
    applyListFilters();
  };

  const openQuiz = (quiz, tab = 'questions') => {
    const restoredQuiz = recoverQuiz(normaliseQuiz(quiz));
    state = {...state, view: 'builder', tab, quiz: restoredQuiz, analytics: null, revisions: null, validationIssues: [], activeQuestionKey: String(restoredQuiz.questions?.[0]?.settings?.key || ''), dirty: restoredQuiz !== quiz && Boolean(localStorage.getItem(recoveryKey(restoredQuiz))), conflict: null, autosaveFailures: 0};
    renderBuilder();
    if (tab === 'analytics') loadAnalytics();
    if (tab === 'history') loadRevisions();
  };

  const navButton = (tab, label) => `<button class="nav ${state.tab === tab ? 'active' : ''}" data-tab="${tab}">${label}</button>`;

  const builderActionBar = () => `<footer class="wpqs-builder-actionbar ${state.conflict ? 'has-conflict' : ''}">
    <div class="wpqs-builder-action-status">${state.conflict
      ? '<span class="is-conflict">⚠ Υπάρχει νεότερη έκδοση στον server</span><small>Οι αλλαγές σας παραμένουν τοπικά. Φορτώστε τη νεότερη έκδοση ή κρατήστε αντίγραφο.</small>'
      : `<span class="${!state.online ? 'is-offline' : state.dirty ? 'is-dirty' : 'is-saved'}">${!state.online ? '● Offline — οι αλλαγές κρατούνται τοπικά' : state.dirty ? '● Μη αποθηκευμένες αλλαγές' : '✓ Όλες οι αλλαγές αποθηκεύτηκαν'}</span><small>Αυτόματη αποθήκευση μετά από 4 δευτερόλεπτα · Ctrl/⌘ + S για άμεση αποθήκευση</small>`}</div>
    <div class="wpqs-builder-action-buttons">${state.conflict ? '<button type="button" data-conflict-copy>Αποθήκευση ως αντίγραφο</button><button type="button" class="wpqs-primary" data-conflict-reload>Φόρτωση server</button>' : `${state.tab === 'questions' ? '<button type="button" data-add-question>+ Ερώτηση</button>' : ''}<button type="button" data-validate>Έλεγχος quiz</button><button type="button" data-preview>Δοκιμή</button><button type="button" data-save>Αποθήκευση</button>${WPQS.canPublish ? '<button type="button" class="wpqs-primary" data-publish>Δημοσίευση</button>' : ''}`}</div>
  </footer>`;

  const renderBuilder = () => {
    const quiz = state.quiz = normaliseQuiz(state.quiz);
    if (!state.activeQuestionKey && quiz.questions.length) state.activeQuestionKey = String(quiz.questions[0].settings.key || '');
    root.innerHTML = `<div class="wpqs-builder">
      <aside class="wpqs-sidebar"><div class="brand">Quiz <small>ATELIER</small></div>
        ${navButton('questions', 'Ερωτήσεις')}${navButton('bank', `Βιβλιοθήκη ερωτήσεων (${state.questionBank.length})`)}${navButton('categories', `Κατηγορίες (${state.categories.length})`)}${navButton('settings', 'Ρυθμίσεις')}${navButton('theme', 'Εμφάνιση')}${navButton('results', 'Αποτελέσματα')}${quiz.id ? navButton('workflow', 'Workflow & Έγκριση') : ''}${WPQS.canAnalytics ? navButton('analytics', 'Στατιστικά') : ''}${quiz.id ? navButton('history', 'Ιστορικό εκδόσεων') : ''}
        <div class="side-bottom"><button type="button" data-user-style-open>◐ Το στυλ μου</button><button type="button" data-back>← Πίνακας quiz</button><button type="button" class="wpqs-secondary" data-save>Αποθήκευση</button></div>
      </aside>
      <section class="workspace"><header><input class="quiz-title" value="${esc(quiz.title)}" aria-label="Τίτλος quiz" placeholder="Τίτλος quiz"><span class="saved">${state.conflict ? '⚠ Σύγκρουση έκδοσης' : !state.online ? '● Offline — τοπική αποθήκευση' : state.dirty ? '● Μη αποθηκευμένες αλλαγές' : '✓ Όλες οι αλλαγές αποθηκεύτηκαν'}</span><button type="button" data-preview>Δοκιμή quiz</button>${quiz.id ? '<button type="button" data-builder-embed>Embed</button><button type="button" data-save-template>Αποθήκευση ως Template</button>' : ''}${WPQS.canPublish ? '<button type="button" class="wpqs-primary" data-publish>Δημοσίευση</button>' : ''}</header>
        <div class="canvas">${renderTab()}</div>${builderActionBar()}
      </section>
      <aside class="preview"><div class="wpqs-preview-heading"><small>ΖΩΝΤΑΝΗ ΠΡΟΕΠΙΣΚΟΠΗΣΗ</small><button type="button" data-preview-reset title="Επαναφορά προεπισκόπησης">↻</button></div><div data-preview-surface>${previewMarkup()}</div></aside>
    </div>`;

    ensureButtonTypes();
    bindBuilder();
    bindPreviewEvents();
  };

  const renderTab = () => {
    if (state.tab === 'bank') return questionBankTab();
    if (state.tab === 'categories') return categoriesTab();
    if (state.tab === 'settings') return settingsTab();
    if (state.tab === 'theme') return themeTab();
    if (state.tab === 'results') return resultsTab();
    if (state.tab === 'workflow') return workflowTab();
    if (state.tab === 'analytics') return analyticsTab();
    if (state.tab === 'history') return historyTab();
    return questionsTab();
  };

  const introCard = () => `<section class="intro-card wpqs-intro-editor"><div class="section-heading"><div><span class="wpqs-kicker">ΕΙΣΑΓΩΓΙΚΗ ΣΕΛΙΔΑ</span><h2>Πρώτη εικόνα του quiz</h2><p>Τα πεδία αυτά εμφανίζονται πριν ξεκινήσουν οι ερωτήσεις.</p></div></div>
    <div class="wpqs-intro-fields">
      <label class="wpqs-field wpqs-field-wide"><span>Τίτλος Quiz <em>Υποχρεωτικό</em></span><input data-field="title" value="${esc(state.quiz.title)}" placeholder="π.χ. Πόσο καλά γνωρίζεις την Ελλάδα;" required></label>
      <label class="wpqs-field wpqs-field-wide"><span>Σύντομη περιγραφή</span><textarea data-field="description" rows="3" placeholder="Μία σύντομη περιγραφή για το περιεχόμενο του quiz">${esc(state.quiz.description)}</textarea></label>
      <label class="wpqs-field"><span>Κύριος τίτλος εισαγωγής</span><input data-field="intro.title" value="${esc(state.quiz.settings.intro.title)}" placeholder="Έτοιμοι να ξεκινήσουμε;"></label>
      <label class="wpqs-field"><span>Υπότιτλος</span><input data-field="intro.subtitle" value="${esc(state.quiz.settings.intro.subtitle)}" placeholder="Προσθέστε ένα σύντομο μήνυμα"></label>
      <label class="wpqs-field"><span>Κείμενο κουμπιού</span><input data-field="intro.button" value="${esc(state.quiz.settings.intro.button)}" placeholder="Έναρξη quiz"></label>
    </div>
    <div class="media-row">${state.quiz.settings.intro.image_url ? `<img src="${esc(state.quiz.settings.intro.image_url)}" alt="">` : '<span>Δεν έχει επιλεγεί εικόνα εξωφύλλου</span>'}<button type="button" class="wpqs-link" data-media="intro">Επιλογή εικόνας</button>${state.quiz.settings.intro.image_url ? '<button type="button" class="wpqs-link danger" data-remove-media="intro">Αφαίρεση</button>' : ''}</div>
  </section>`;

  const validationSummary = () => {
    if (!state.validationIssues.length) return '';
    return `<section class="wpqs-validation-summary" role="alert"><div><span class="wpqs-kicker">ΕΛΕΓΧΟΣ QUIZ</span><h3>${state.validationIssues.length} ${state.validationIssues.length === 1 ? 'σημείο χρειάζεται' : 'σημεία χρειάζονται'} διόρθωση</h3><p>Πατήστε σε ένα μήνυμα για να ανοίξει το αντίστοιχο πεδίο.</p></div><ol>${state.validationIssues.map((issue,index)=>`<li><button type="button" data-validation-jump="${index}">${esc(issue.message)}</button></li>`).join('')}</ol></section>`;
  };

  const questionsTab = () => `${introCard()}${validationSummary()}<div class="section-heading wpqs-questions-heading"><div><h3>Ερωτήσεις</h3><p>${state.quiz.questions.length} συνολικά · ανοίγει μία ερώτηση κάθε φορά για καθαρότερο Builder.</p></div><button type="button" class="wpqs-link" data-add-question>+ Προσθήκη ερώτησης</button></div><div id="questions">${state.quiz.questions.map(questionCard).join('') || '<div class="empty-panel">Προσθέστε μία ερώτηση για να ξεκινήσετε.</div>'}</div><div class="wpqs-add-question-footer"><button type="button" class="wpqs-add-question-large" data-add-question><span class="wpqs-add-question-icon" aria-hidden="true">+</span><span><strong>Προσθήκη ερώτησης</strong><small>Δημιουργήστε την επόμενη ερώτηση του quiz</small></span></button></div>`;

  const conditionMarkup = (question, index) => {
    const condition = question.settings.condition || {enabled: false, match: 'all', rules: []};
    const previous = state.quiz.questions.slice(0, index).filter(item => item.type !== 'open_text');
    let rules = Array.isArray(condition.rules) ? condition.rules : [];
    if (!rules.length && previous.length) rules = [{operator: 'equals', question_key: previous[0].settings.key, answer_key: previous[0].answers?.[0]?.content?.key || ''}];
    if (!previous.length) {
      return `<section class="conditional-box is-unavailable" aria-label="Συνθήκη εμφάνισης">
        <label class="check wpqs-condition-switch"><input type="checkbox" disabled> <span>Εμφάνιση με όρους</span><small>Μη διαθέσιμο</small></label>
        <p class="wpqs-condition-description">Η πρώτη ερώτηση εμφανίζεται πάντα. Για να χρησιμοποιήσετε όρους, προσθέστε πρώτα μία προηγούμενη ερώτηση επιλογής.</p>
      </section>`;
    }
    const rows = rules.map((rule, ruleIndex) => {
      const source = previous.find(item => item.settings.key === rule.question_key) || previous[0];
      const answers = source.answers || [];
      const needsAnswer = ['equals','not_equals'].includes(rule.operator);
      return `<div class="condition-rule">
        <label>Ερώτηση<select data-condition-rule-question data-index="${index}" data-rule="${ruleIndex}">${previous.map(item => `<option value="${esc(item.settings.key)}" ${item.settings.key === source.settings.key ? 'selected' : ''}>${esc(item.content.title || 'Χωρίς τίτλο')}</option>`).join('')}</select></label>
        <label>Κανόνας<select data-condition-rule-operator data-index="${index}" data-rule="${ruleIndex}"><option value="equals" ${rule.operator === 'equals' ? 'selected' : ''}>Η απάντηση είναι</option><option value="not_equals" ${rule.operator === 'not_equals' ? 'selected' : ''}>Η απάντηση δεν είναι</option><option value="answered" ${rule.operator === 'answered' ? 'selected' : ''}>Έχει απαντηθεί</option><option value="not_answered" ${rule.operator === 'not_answered' ? 'selected' : ''}>Δεν έχει απαντηθεί</option></select></label>
        <label class="${needsAnswer ? '' : 'is-disabled'}">Απάντηση<select data-condition-rule-answer data-index="${index}" data-rule="${ruleIndex}" ${needsAnswer ? '' : 'disabled'}>${answers.map(answer => `<option value="${esc(answer.content.key)}" ${answer.content.key === rule.answer_key ? 'selected' : ''}>${esc(answer.content.text || 'Χωρίς κείμενο')}</option>`).join('')}</select></label>
        <button class="wpqs-link danger" type="button" data-condition-delete-rule data-index="${index}" data-rule="${ruleIndex}" ${rules.length <= 1 ? 'disabled' : ''} aria-label="Διαγραφή κανόνα">×</button>
      </div>`;
    }).join('');
    return `<section class="conditional-box ${condition.enabled ? 'is-enabled' : ''}" aria-label="Συνθήκη εμφάνισης">
      <label class="check wpqs-condition-switch"><input type="checkbox" data-condition-enabled data-index="${index}" ${condition.enabled ? 'checked' : ''}> <span>Εμφάνιση με όρους</span><small>${condition.enabled ? 'Ενεργό' : 'Ανενεργό'}</small></label>
      <p class="wpqs-condition-description">Όταν ενεργοποιηθεί, η συγκεκριμένη ερώτηση εμφανίζεται μόνο αν οι απαντήσεις σε προηγούμενες ερωτήσεις ικανοποιούν τους κανόνες.</p>
      <div class="condition-builder" ${condition.enabled ? '' : 'hidden'}>
        <div class="condition-match"><span>Εμφάνιση όταν</span><select data-condition-match data-index="${index}"><option value="all" ${condition.match !== 'any' ? 'selected' : ''}>ισχύουν όλοι οι κανόνες (AND)</option><option value="any" ${condition.match === 'any' ? 'selected' : ''}>ισχύει οποιοσδήποτε κανόνας (OR)</option></select></div>
        ${rows}<button class="wpqs-link" type="button" data-condition-add-rule data-index="${index}">+ Προσθήκη κανόνα</button>
      </div>
    </section>`;
  };

  const questionCard = (question, index) => {
    const questionKey = String(question.settings?.key || index);
    const isActive = state.activeQuestionKey ? state.activeQuestionKey === questionKey : index === 0;
    const issueCount = state.validationIssues.filter(issue => issue.questionIndex === index).length;
    const multiCorrect = question.type === 'multiple_answers' || question.type === 'open_text';
    const valueType = ['slider','numeric','rating'].includes(question.type);
    const orderedType = ['ordering','ranking'].includes(question.type);
    const matchingType = question.type === 'matching';
    const personality = state.quiz.quiz_type === 'personality';
    const noCorrect = question.type === 'poll' || personality || orderedType || matchingType || valueType;
    const profiles = state.quiz.settings.personality_profiles || [];
    const personalityFields = (answer, answerIndex) => personality && profiles.length ? `<div class="personality-weight-grid"><small>Βαθμοί προσωπικότητας</small>${profiles.map(profile => `<label>${esc(profile.title)}<input type="number" step="0.5" data-personality-weight data-question="${index}" data-answer="${answerIndex}" data-profile="${esc(profile.key)}" value="${esc(number(answer.content.personality_weights?.[profile.key]))}"></label>`).join('')}</div>` : '';
    const answerRows = valueType ? '' : question.answers.map((answer, answerIndex) => `<div class="answer-row ${orderedType ? 'is-order-item' : ''}" data-answer-row="${answerIndex}" data-question-index="${index}">
        <span class="answer-drag" draggable="true" data-answer-drag data-question="${index}" data-answer="${answerIndex}" title="Σύρετε για αλλαγή σειράς">☰</span>${orderedType ? '' : noCorrect ? '<span class="answer-dot">•</span>' : `<input type="${multiCorrect ? 'checkbox' : 'radio'}" data-correct-question="${index}" data-correct-answer="${answerIndex}" name="correct-${index}" ${answer.is_correct ? 'checked' : ''} aria-label="Σωστή απάντηση">`}
        <div class="answer-editor"><input data-answer-question="${index}" data-answer="${answerIndex}" value="${esc(answer.content.text)}" ${question.type === 'true_false' ? 'readonly aria-readonly="true"' : ''} placeholder="${question.type === 'open_text' ? 'Αποδεκτή απάντηση' : matchingType ? 'Αριστερή τιμή' : 'Απάντηση'}">${matchingType ? `<input class="match-input" data-answer-match data-question="${index}" data-answer="${answerIndex}" value="${esc(answer.content.match_text || '')}" placeholder="Σωστή αντιστοίχιση">` : ''}${question.type === 'image_choice' ? `<div class="answer-media">${answer.content.image_url ? `<img src="${esc(answer.content.image_url)}" alt="">` : ''}<button type="button" class="wpqs-link" data-media="answer" data-question="${index}" data-answer="${answerIndex}">${answer.content.image_url ? 'Αλλαγή εικόνας' : 'Προσθήκη εικόνας'}</button>${answer.content.image_url ? `<button type="button" class="wpqs-link danger" data-remove-media="answer" data-question="${index}" data-answer="${answerIndex}">Αφαίρεση</button>` : ''}</div>` : ''}${personalityFields(answer, answerIndex)}</div>
        ${noCorrect ? '' : `<input class="score-input" type="number" step="0.5" data-score-question="${index}" data-score-answer="${answerIndex}" value="${esc(answer.score)}" title="Βαθμοί">`}
        ${orderedType ? `<div class="order-tools"><button type="button" data-move-answer="${index}" data-answer="${answerIndex}" data-direction="-1">↑</button><button type="button" data-move-answer="${index}" data-answer="${answerIndex}" data-direction="1">↓</button></div>` : ''}
        <button type="button" class="answer-delete" data-delete-answer="${index}" data-answer="${answerIndex}" aria-label="Διαγραφή απάντησης">×</button>
      </div>`).join('');
    const advanced = question.type === 'multiple_choice' || question.type === 'image_choice'
      ? `<div class="advanced-type-settings settings-grid two">
          <div class="wpqs-type-summary"><span>Μία σωστή απάντηση</span><strong>${esc(question.answers.find(answer => bool(answer.is_correct))?.content?.text || 'Δεν έχει επιλεγεί')}</strong></div>
          <label>Προεπιλεγμένοι βαθμοί<input type="number" min="0" step="0.5" data-question-setting="points" data-index="${index}" value="${esc(question.settings.points)}"></label>
          <p class="description">Επιλέξτε τη σωστή απάντηση από τα radio controls στη λίστα. Στην επιλογή εικόνας προσθέστε εικόνα ξεχωριστά σε κάθε απάντηση.</p>
        </div>`
      : question.type === 'true_false'
      ? `<div class="advanced-type-settings settings-grid two">
          <label>Σωστή απάντηση<select data-true-false-correct data-index="${index}">
            <option value="0" ${bool(question.answers[0]?.is_correct) ? 'selected' : ''}>Σωστό</option>
            <option value="1" ${bool(question.answers[1]?.is_correct) ? 'selected' : ''}>Λάθος</option>
          </select></label>
          <label>Βαθμοί σωστής απάντησης<input type="number" min="0" step="0.5" data-question-setting="points" data-index="${index}" value="${esc(question.settings.points)}"></label>
          <p class="description">Οι επιλογές «Σωστό» και «Λάθος» δημιουργούνται και κλειδώνουν αυτόματα. Επιλέξτε εδώ ποια είναι η σωστή.</p>
        </div>`
      : question.type === 'poll'
      ? `<div class="advanced-type-settings"><div class="wpqs-type-summary is-poll"><span>Τύπος δημοσκόπησης</span><strong>Δεν υπάρχει σωστή ή λάθος απάντηση</strong><small>Κάθε επιλογή καταγράφεται ως ψήφος και εμφανίζεται στα analytics.</small></div></div>`
      : question.type === 'slider' ? `<div class="advanced-type-settings settings-grid three"><label>Ελάχιστο<input type="number" data-question-setting="slider_min" data-index="${index}" value="${esc(question.settings.slider_min)}"></label><label>Μέγιστο<input type="number" data-question-setting="slider_max" data-index="${index}" value="${esc(question.settings.slider_max)}"></label><label>Βήμα<input type="number" step="0.01" data-question-setting="slider_step" data-index="${index}" value="${esc(question.settings.slider_step)}"></label><label>Σωστό από<input type="number" data-question-setting="correct_min" data-index="${index}" value="${esc(question.settings.correct_min)}"></label><label>Σωστό έως<input type="number" data-question-setting="correct_max" data-index="${index}" value="${esc(question.settings.correct_max)}"></label><label>Βαθμοί<input type="number" min="0" step="0.5" data-question-setting="points" data-index="${index}" value="${esc(question.settings.points)}"></label></div>`
      : question.type === 'numeric' ? `<div class="advanced-type-settings settings-grid three"><label>Σωστός αριθμός<input type="number" step="any" data-question-setting="numeric_answer" data-index="${index}" value="${esc(question.settings.numeric_answer)}"></label><label>Ανοχή ±<input type="number" min="0" step="any" data-question-setting="numeric_tolerance" data-index="${index}" value="${esc(question.settings.numeric_tolerance)}"></label><label>Βαθμοί<input type="number" min="0" step="0.5" data-question-setting="points" data-index="${index}" value="${esc(question.settings.points)}"></label></div>`
      : question.type === 'rating' ? `<div class="advanced-type-settings settings-grid three"><label>Μέγιστη αξιολόγηση<input type="number" min="2" max="20" data-question-setting="rating_max" data-index="${index}" value="${esc(question.settings.rating_max)}"></label><label>Εμφάνιση<select data-question-setting="rating_style" data-index="${index}"><option value="stars" ${question.settings.rating_style !== 'numbers' ? 'selected' : ''}>Αστέρια</option><option value="numbers" ${question.settings.rating_style === 'numbers' ? 'selected' : ''}>Αριθμοί</option></select></label><label>Βαθμοί<input type="number" min="0" step="0.5" data-question-setting="points" data-index="${index}" value="${esc(question.settings.points)}"></label><label>Σωστό από<input type="number" min="1" data-question-setting="correct_min" data-index="${index}" value="${esc(question.settings.correct_min)}"></label><label>Σωστό έως<input type="number" min="1" data-question-setting="correct_max" data-index="${index}" value="${esc(question.settings.correct_max)}"></label></div>`
      : question.type === 'multiple_answers' ? `<div class="advanced-type-settings settings-grid two"><label>Τρόπος βαθμολόγησης<select data-question-setting="multiple_scoring" data-index="${index}"><option value="exact" ${question.settings.multiple_scoring !== 'partial' ? 'selected' : ''}>Μόνο όταν είναι όλες σωστές</option><option value="partial" ${question.settings.multiple_scoring === 'partial' ? 'selected' : ''}>Μερική βαθμολογία</option></select></label><label>Προεπιλεγμένοι βαθμοί<input type="number" min="0" step="0.5" data-question-setting="points" data-index="${index}" value="${esc(question.settings.points)}"></label><p class="description">Στη μερική βαθμολογία κάθε σωστή επιλογή προσθέτει βαθμούς και κάθε λάθος επιλογή αφαιρεί ένα ίσο μέρος.</p></div>`
      : question.type === 'open_text' ? `<div class="advanced-type-settings settings-grid three"><label class="check"><input type="checkbox" data-question-setting="text_case_sensitive" data-index="${index}" ${question.settings.text_case_sensitive ? 'checked' : ''}> Διάκριση πεζών/κεφαλαίων</label><label class="check"><input type="checkbox" data-question-setting="text_ignore_accents" data-index="${index}" ${question.settings.text_ignore_accents !== false ? 'checked' : ''}> Αγνόηση τόνων</label><label class="check"><input type="checkbox" data-question-setting="text_ignore_punctuation" data-index="${index}" ${question.settings.text_ignore_punctuation !== false ? 'checked' : ''}> Αγνόηση σημείων στίξης</label></div>`
      : (orderedType || matchingType) ? `<div class="advanced-type-settings settings-grid two"><label>Βαθμοί<input type="number" min="0" step="0.5" data-question-setting="points" data-index="${index}" value="${esc(question.settings.points)}"></label><label>Τρόπος βαθμολόγησης<select data-question-setting="${matchingType ? 'matching_scoring' : 'order_scoring'}" data-index="${index}"><option value="exact" ${(matchingType ? question.settings.matching_scoring : question.settings.order_scoring) !== 'partial' ? 'selected' : ''}>Μόνο απόλυτα σωστό</option><option value="partial" ${(matchingType ? question.settings.matching_scoring : question.settings.order_scoring) === 'partial' ? 'selected' : ''}>Μερική βαθμολογία</option></select></label><p class="description">${matchingType ? 'Κάθε αριστερή τιμή αντιστοιχεί στη δεξιά τιμή της ίδιας σειράς.' : 'Η σειρά των απαντήσεων στον builder είναι η σωστή σειρά.'}</p></div>`
      : `<div class="advanced-type-settings"><div class="wpqs-type-summary"><span>${esc(typeLabel(question.type))}</span><strong>Οι ειδικές ρυθμίσεις αυτού του τύπου είναι ενεργές.</strong></div></div>`;

    const typePanelDescriptions = {
      multiple_choice: 'Επιλέξτε ακριβώς μία σωστή απάντηση και ορίστε τους βαθμούς της.',
      multiple_answers: 'Επιλέξτε περισσότερες από μία σωστές απαντήσεις και τον τρόπο βαθμολόγησης.',
      true_false: 'Ορίστε αν η σωστή επιλογή είναι «Σωστό» ή «Λάθος».',
      image_choice: 'Προσθέστε εικόνα σε κάθε απάντηση και επιλέξτε μία σωστή επιλογή.',
      poll: 'Οι επιλογές καταγράφονται ως ψήφοι χωρίς βαθμολόγηση.',
      open_text: 'Οι γραμμές απαντήσεων παρακάτω είναι οι αποδεκτές λεκτικές απαντήσεις.',
      numeric: 'Ορίστε τον σωστό αριθμό, το επιτρεπόμενο περιθώριο απόκλισης και τους βαθμούς.',
      slider: 'Ορίστε τα όρια της κλίμακας, το βήμα και το εύρος τιμών που θεωρείται σωστό.',
      rating: 'Ρυθμίστε τον αριθμό επιπέδων, την εμφάνιση και το σωστό εύρος αξιολόγησης.',
      ordering: 'Η σειρά των στοιχείων μέσα στον builder αποτελεί τη σωστή σειρά.',
      ranking: 'Η σειρά των στοιχείων μέσα στον builder αποτελεί την αναμενόμενη κατάταξη.',
      matching: 'Κάθε αριστερή τιμή πρέπει να αντιστοιχεί στη δεξιά τιμή της ίδιας γραμμής.'
    };
    const advancedPanel = advanced ? `<section class="wpqs-type-panel"><div class="wpqs-type-panel-head"><strong>Ρυθμίσεις τύπου: ${esc(typeLabel(question.type))}</strong><small>${esc(typePanelDescriptions[question.type] || 'Προσαρμόστε τον τρόπο λειτουργίας και βαθμολόγησης της ερώτησης.')}</small></div>${advanced}</section>` : '';
    const answerSectionTitle = question.type === 'open_text' ? 'Αποδεκτές απαντήσεις' : matchingType ? 'Ζεύγη αντιστοίχισης' : orderedType ? (question.type === 'ranking' ? 'Στοιχεία κατάταξης' : 'Στοιχεία σωστής σειράς') : question.type === 'poll' ? 'Επιλογές δημοσκόπησης' : question.type === 'image_choice' ? 'Απαντήσεις με εικόνα' : 'Απαντήσεις';
    const answerSectionDescription = question.type === 'open_text' ? 'Προσθέστε όλες τις λεκτικές μορφές που πρέπει να θεωρούνται σωστές.' : matchingType ? 'Συμπληρώστε αριστερή και δεξιά τιμή σε κάθε γραμμή.' : orderedType ? 'Μετακινήστε τις γραμμές ώστε να βρίσκονται στη σωστή σειρά.' : question.type === 'poll' ? 'Οι επιλογές καταγράφονται ως ψήφοι και δεν έχουν σωστή απάντηση.' : question.type === 'multiple_answers' ? 'Μπορείτε να επιλέξετε περισσότερες από μία σωστές απαντήσεις.' : question.type === 'true_false' ? 'Επιλέξτε ποια από τις δύο σταθερές επιλογές είναι σωστή.' : 'Επιλέξτε τη σωστή απάντηση και ορίστε τη βαθμολογία της.';
    return `<article class="question-card question-type-${question.type} ${isActive ? 'is-active' : 'is-collapsed'} ${issueCount ? 'has-validation-issues' : ''}" data-question-card="${index}" data-active-question-type="${esc(question.type)}">
      <div class="question-head"><span class="wpqs-question-drag-handle" draggable="true" data-question-drag="${index}" title="Σύρετε για αλλαγή σειράς">⋮⋮</span><button type="button" class="wpqs-question-toggle" data-question-toggle="${index}" aria-expanded="${isActive ? 'true' : 'false'}"><span class="wpqs-question-number">ΕΡΩΤΗΣΗ ${index + 1}</span><span class="wpqs-question-summary"><strong>${esc(question.content.title || 'Χωρίς τίτλο')}</strong><small>${esc(typeLabel(question.type))} · ${question.answers.length} ${question.answers.length === 1 ? 'απάντηση' : 'απαντήσεις'}${issueCount ? ` · ${issueCount} σφάλμα${issueCount === 1 ? '' : 'τα'}` : ''}</small></span><span class="wpqs-question-chevron">${isActive ? '⌃' : '⌄'}</span></button><div class="question-tools">
        <button type="button" title="Μετακίνηση πάνω" data-move-question="${index}" data-direction="-1">↑</button><button type="button" title="Μετακίνηση κάτω" data-move-question="${index}" data-direction="1">↓</button><button type="button" class="wpqs-question-library-button" data-save-bank="${index}" aria-label="Αποθήκευση ερώτησης στη βιβλιοθήκη" title="Αποθήκευση ερώτησης στη βιβλιοθήκη">▣</button><button type="button" data-duplicate-question="${index}">Αντιγραφή</button><button type="button" class="danger" data-delete-question="${index}">Διαγραφή</button>
      </div></div>
      <div class="wpqs-question-type-row"><span class="wpqs-question-type-badge">${esc(typeLabel(question.type))}</span>
        <label class="wpqs-question-type-control"><span>Τύπος ερώτησης</span><select data-question-type="${index}">
          <optgroup label="Επιλογές"><option value="multiple_choice" ${question.type === 'multiple_choice' ? 'selected' : ''}>Μία επιλογή</option><option value="multiple_answers" ${question.type === 'multiple_answers' ? 'selected' : ''}>Πολλαπλές επιλογές</option><option value="true_false" ${question.type === 'true_false' ? 'selected' : ''}>Σωστό / Λάθος</option><option value="image_choice" ${question.type === 'image_choice' ? 'selected' : ''}>Επιλογή εικόνας</option><option value="poll" ${question.type === 'poll' ? 'selected' : ''}>Δημοσκόπηση</option></optgroup>
          <optgroup label="Απαντήσεις"><option value="open_text" ${question.type === 'open_text' ? 'selected' : ''}>Ανοιχτό κείμενο</option><option value="numeric" ${question.type === 'numeric' ? 'selected' : ''}>Αριθμητική απάντηση</option><option value="slider" ${question.type === 'slider' ? 'selected' : ''}>Κλίμακα</option><option value="rating" ${question.type === 'rating' ? 'selected' : ''}>Αξιολόγηση</option></optgroup>
          <optgroup label="Διαδραστικές"><option value="ordering" ${question.type === 'ordering' ? 'selected' : ''}>Ταξινόμηση σειράς</option><option value="ranking" ${question.type === 'ranking' ? 'selected' : ''}>Κατάταξη</option><option value="matching" ${question.type === 'matching' ? 'selected' : ''}>Αντιστοίχιση</option></optgroup>
        </select></label>
        <p class="wpqs-question-type-help">${({
          multiple_choice:'Μία επιλογή και μία σωστή απάντηση.',
          multiple_answers:'Ο επισκέπτης μπορεί να επιλέξει περισσότερες από μία απαντήσεις.',
          true_false:'Οι επιλογές «Σωστό» και «Λάθος» δημιουργούνται αυτόματα.',
          image_choice:'Κάθε απάντηση μπορεί να έχει τη δική της εικόνα.',
          poll:'Καταγράφει ψήφους χωρίς σωστή ή λάθος απάντηση.',
          open_text:'Προσθέστε μία ή περισσότερες αποδεκτές λεκτικές απαντήσεις.',
          numeric:'Ορίστε σωστό αριθμό και προαιρετική ανοχή.',
          slider:'Ορίστε εύρος, βήμα και σωστό διάστημα τιμών.',
          rating:'Ορίστε μέγιστη αξιολόγηση και σωστό διάστημα.',
          ordering:'Η σειρά των γραμμών στον builder είναι η σωστή σειρά.',
          ranking:'Ο χρήστης κατατάσσει τις επιλογές στη σωστή σειρά.',
          matching:'Κάθε αριστερή τιμή αντιστοιχεί στη δεξιά τιμή της ίδιας γραμμής.'
        }[question.type] || 'Η αλλαγή τύπου κρατά τις μη αποθηκευμένες αλλαγές σας.')}</p>
      </div>
      <input class="question-input" data-question-title="${index}" value="${esc(question.content.title)}" placeholder="Γράψτε την ερώτηση">
      <div class="media-row compact">${question.content.image_url ? `<img src="${esc(question.content.image_url)}" alt="">` : '<span>Χωρίς εικόνα ερώτησης</span>'}<button type="button" class="wpqs-link" data-media="question" data-index="${index}">Επιλογή εικόνας</button>${question.content.image_url ? `<button type="button" class="wpqs-link danger" data-remove-media="question" data-index="${index}">Αφαίρεση</button>` : ''}</div>
      ${advancedPanel}
      ${valueType ? '' : `<section class="wpqs-answer-section"><div class="wpqs-answer-section-head"><div><h4>${esc(answerSectionTitle)}</h4><p>${esc(answerSectionDescription)}</p></div></div><div class="answers">${answerRows}<button type="button" class="wpqs-link" data-add-answer="${index}">+ Προσθήκη ${matchingType ? 'ζεύγους' : 'απάντησης'}</button></div></section>`}
      ${personality && !profiles.length ? '<div class="notice-inline">Δημιουργήστε πρώτα προφίλ από την καρτέλα «Αποτελέσματα», για να ορίσετε βαθμούς προσωπικότητας.</div>' : ''}
      <details class="question-settings" data-question-settings="${index}" ${state.openQuestionSettings === index ? 'open' : ''}><summary>Ρυθμίσεις ερώτησης</summary><div class="settings-grid">
        <label>Βοήθεια<input data-question-setting="hint" data-index="${index}" value="${esc(question.settings.hint)}"></label>
        <label>Επεξήγηση<textarea data-question-setting="explanation" data-index="${index}" placeholder="Εμφανίζεται αφού δοθεί η απάντηση">${esc(question.settings.explanation)}</textarea></label>
        <label>Χρονόμετρο (δευτερόλεπτα)<input type="number" min="0" max="3600" data-question-setting="timer" data-index="${index}" value="${esc(question.settings.timer)}"></label>
        <label class="check"><input type="checkbox" data-question-setting="required" data-index="${index}" ${question.settings.required ? 'checked' : ''}> Υποχρεωτική</label>
        ${valueType || orderedType || matchingType ? '' : `<label class="check"><input type="checkbox" data-question-setting="shuffle_answers" data-index="${index}" ${question.settings.shuffle_answers ? 'checked' : ''}> Τυχαία σειρά απαντήσεων</label>`}
      </div>${conditionMarkup(question, index)}</details>
    </article>`;
  };

  /**
   * Re-renders only one question card. This avoids full-builder refreshes,
   * scroll jumps and stale type panels when the question type changes.
   */
  const refreshQuestionCard = (index, focusSelector = '') => {
    const current = root.querySelector(`[data-question-card="${index}"]`);
    const question = state.quiz?.questions?.[index];
    if (!current || !question) {
      preserveBuilderPosition(renderBuilder, focusSelector);
      return;
    }

    const topBefore = current.getBoundingClientRect().top;
    const workspace = root.querySelector('.wpqs-builder .workspace');
    const workspaceTop = workspace?.scrollTop || 0;
    const template = document.createElement('template');
    template.innerHTML = questionCard(question, index).trim();
    const replacement = template.content.firstElementChild;
    if (!replacement) return;

    current.replaceWith(replacement);
    ensureButtonTypes(replacement);
    bindQuestionEvents();
    bindMediaEvents();
    refreshPreview();

    requestAnimationFrame(() => {
      if (workspace) workspace.scrollTop = workspaceTop;
      const nextCard = root.querySelector(`[data-question-card="${index}"]`);
      if (nextCard) window.scrollBy({top: nextCard.getBoundingClientRect().top - topBefore, left: 0, behavior: 'auto'});
      const focus = focusSelector ? root.querySelector(focusSelector) : null;
      focus?.focus({preventScroll: true});
    });
  };

  const questionBankTab = () => `<section class="panel"><div class="section-heading"><div><h2>Βιβλιοθήκη ερωτήσεων</h2><p>Αποθηκεύστε και επαναχρησιμοποιήστε ερωτήσεις σε διαφορετικά quiz.</p></div></div><div class="question-bank-list">${state.questionBank.map(item => `<article class="bank-card"><div><strong>${esc(item.title)}</strong><small>${esc(typeLabel(item.type))} · ${esc(formatDate(item.updated_at))}</small></div><div><button class="wpqs-link" data-bank-insert="${item.id}">Εισαγωγή</button>${WPQS.canDelete ? `<button class="wpqs-link danger" data-bank-delete="${item.id}">Διαγραφή</button>` : ''}</div></article>`).join('') || '<div class="empty-panel">Η βιβλιοθήκη ερωτήσεων είναι κενή. Πατήστε το εικονίδιο βιβλιοθήκης σε μία ερώτηση.</div>'}</div></section>`;

  const categoryManagerMarkup = (standalone = false) => {
    const query = state.categoryQuery.trim().toLocaleLowerCase('el');
    const categories = state.categories.filter(category => !query || `${category.name} ${category.slug} ${category.description || ''}`.toLocaleLowerCase('el').includes(query));
    const rows = categories.map(category => `<article class="wpqs-category-row" data-category-item="${category.id}">
      <div class="wpqs-category-symbol" style="--category-color:${esc(category.color || '#d9bd85')}">${esc(categoryIcons[category.icon] || categoryIcons.folder)}</div>
      <div class="wpqs-category-main"><div><h3>${esc(category.name)}</h3><code>${esc(category.slug)}</code></div><p>${esc(category.description || 'Χωρίς περιγραφή')}</p></div>
      <div class="wpqs-category-count"><b>${number(category.quiz_count)}</b><span>quiz</span></div>
      <div class="wpqs-category-actions"><button data-category-edit="${category.id}">Επεξεργασία</button>${WPQS.canDelete ? `<button class="danger" data-category-delete="${category.id}">Διαγραφή</button>` : ''}</div>
    </article>`).join('');
    const iconOptions = Object.keys(categoryIcons).map(icon => `<option value="${icon}">${esc(categoryIcons[icon])} ${esc(categoryIconLabel(icon))}</option>`).join('');
    return `<div class="wpqs-category-manager ${standalone ? 'is-standalone' : ''}">
      <section class="wpqs-category-list-panel">
        <div class="section-heading"><div><h2>Κατηγορίες</h2><p>Οργανώστε τα quiz και εμφανίστε καθαρές κατηγορίες στο frontend.</p></div><span>${state.categories.length} συνολικά</span></div>
        <label class="wpqs-search wpqs-category-search"><span>⌕</span><input value="${esc(state.categoryQuery)}" data-category-search placeholder="Αναζήτηση κατηγορίας"></label>
        <div class="wpqs-category-list">${rows || '<div class="empty-panel">Δεν υπάρχουν κατηγορίες με αυτά τα κριτήρια.</div>'}</div>
      </section>
      <aside class="wpqs-category-editor" data-category-form>
        <span class="wpqs-kicker" data-category-form-kicker>ΝΕΑ ΚΑΤΗΓΟΡΙΑ</span><h2 data-category-form-title>Δημιουργία κατηγορίας</h2><p>Δώστε όνομα, χρώμα και εικονίδιο. Το slug δημιουργείται αυτόματα όταν μείνει κενό.</p>
        <label>Όνομα<input data-category-name placeholder="π.χ. Αθλητικά"></label>
        <label>Slug<input data-category-slug placeholder="athlitika"></label>
        <label>Περιγραφή<textarea rows="4" data-category-description placeholder="Σύντομη περιγραφή για το frontend"></textarea></label>
        <div class="settings-grid two"><label>Χρώμα<span class="color-control"><input type="color" data-category-color value="#d9bd85"><input data-category-color-text value="#d9bd85"></span></label><label>Εικονίδιο<select data-category-icon>${iconOptions}</select></label></div>
        <div class="wpqs-category-preview" data-category-preview><span>◫</span><div><b>Νέα κατηγορία</b><small>category-slug</small></div></div>
        <div class="category-form-actions"><button class="wpqs-primary" data-category-save>Αποθήκευση κατηγορίας</button><button data-category-cancel>Καθαρισμός</button></div>
        <div class="notice-inline"><strong>Frontend:</strong> <code>[wp_quiz_studio_directory]</code><br><strong>Μία κατηγορία:</strong> <code>[wp_quiz_studio_directory category="slug"]</code></div>
      </aside>
    </div>`;
  };

  const categoriesTab = () => categoryManagerMarkup(false);

  const renderCategoriesPage = () => {
    state.view = 'categories';
    const assigned = state.categories.reduce((sum, category) => sum + number(category.quiz_count), 0);
    const uncategorized = state.quizzes.filter(quiz => !number(quiz.category_id)).length;
    root.innerHTML = `<main class="wpqs-dashboard wpqs-portal-page wpqs-categories-page">${portalNav('categories')}
      <header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">QUIZ ATELIER ${esc(WPQS.version || '1.0.0')}</span><h1>Κατηγορίες</h1><p>Δημιουργήστε μια καθαρή δομή για τη βιβλιοθήκη και τον δημόσιο κατάλογο των quiz.</p></div><div class="wpqs-header-actions"><button class="wpqs-ghost" data-categories-back>← Πίνακας quiz</button><button class="wpqs-ghost" data-user-style-open>Το στυλ μου</button><button class="wpqs-primary" data-category-new>+ Νέα κατηγορία</button></div></header>
      <section class="wpqs-cards wpqs-category-summary"><article><span>Κατηγορίες</span><b>${state.categories.length}</b><small>συνολικά</small></article><article><span>Quiz με κατηγορία</span><b>${assigned}</b><small>συνδεδεμένα</small></article><article><span>Χωρίς κατηγορία</span><b>${uncategorized}</b><small>χρειάζονται οργάνωση</small></article></section>
      ${categoryManagerMarkup(true)}
    </main>`;
    bindPortalNav();
    root.querySelector('[data-categories-back]')?.addEventListener('click', renderList);
    bindCategoryEvents();
  };

  const categoryOptions = () => `<option value="0">— Χωρίς κατηγορία —</option>${state.categories.map(category => `<option value="${category.id}" ${number(state.quiz.category_id) === number(category.id) ? 'selected' : ''}>${esc(category.name)}</option>`).join('')}`;

  const settingsTab = () => `<section class="panel"><h2>Ρυθμίσεις quiz</h2><div class="settings-grid two">
    <label>Slug<input data-setting="slug" value="${esc(state.quiz.slug)}" placeholder="δημιουργείται-από-τον-τίτλο"></label>
    <label>Τύπος quiz<select data-setting="quiz_type">${quizTypes.map(type => `<option value="${type}" ${state.quiz.quiz_type === type ? 'selected' : ''}>${esc(quizTypeLabel(type))}</option>`).join('')}</select></label>
    <label>Κατηγορία<select data-setting="category_id">${categoryOptions()}</select></label>
    <label>Ορατότητα<select data-setting="visibility_scope"><option value="personal" ${state.quiz.visibility_scope === 'personal' ? 'selected' : ''}>Private — μόνο ο δημιουργός και admins</option><option value="organization" ${state.quiz.visibility_scope === 'organization' ? 'selected' : ''}>Organization — όλη η ομάδα</option>${WPQS.canManageUniversal || state.quiz.visibility_scope === 'universal' ? `<option value="universal" ${state.quiz.visibility_scope === 'universal' ? 'selected' : ''}>Universal — όλοι οι εγκεκριμένοι χρήστες</option>` : ''}</select></label>
    <label>Κατάσταση<select data-setting="status"><option value="draft" ${state.quiz.status === 'draft' ? 'selected' : ''}>Πρόχειρο</option><option value="published" ${state.quiz.status === 'published' ? 'selected' : ''}>Δημοσιευμένο</option><option value="scheduled" ${state.quiz.status === 'scheduled' ? 'selected' : ''}>Προγραμματισμένο</option><option value="private" ${state.quiz.status === 'private' ? 'selected' : ''}>Ιδιωτικό</option>${state.quiz.status === 'expired' ? '<option value="expired" selected>Έληξε</option>' : ''}</select></label>
    <label>Ημερομηνία δημοσίευσης<input type="datetime-local" data-setting="scheduled_at" value="${esc(localDateTime(state.quiz.scheduled_at))}" ${state.quiz.status !== 'scheduled' ? 'disabled' : ''}></label>
    <label>Ημερομηνία λήξης<input type="datetime-local" data-setting="expires_at" value="${esc(localDateTime(state.quiz.expires_at))}"><small>Μετά τη λήξη το quiz δεν ανοίγει στη δημόσια προβολή.</small></label>
    <label class="check"><input type="checkbox" data-setting="show_progress" ${state.quiz.settings.show_progress ? 'checked' : ''}> Εμφάνιση γραμμής προόδου</label>
    <label class="check"><input type="checkbox" data-setting="random_questions" ${state.quiz.settings.random_questions ? 'checked' : ''}> Τυχαία σειρά ερωτήσεων</label>
    <label>Πλήθος τυχαίων ερωτήσεων<input type="number" min="0" max="500" data-setting="random_question_limit" value="${esc(state.quiz.settings.random_question_limit)}"><small>0 = όλες οι ερωτήσεις.</small></label>
    <label class="check"><input type="checkbox" data-setting="show_feedback" ${state.quiz.settings.show_feedback ? 'checked' : ''}> Άμεση ενημέρωση σωστού/λάθους</label>
    <label class="check"><input type="checkbox" data-setting="show_correct_answer" ${state.quiz.settings.show_correct_answer ? 'checked' : ''}> Εμφάνιση σωστής απάντησης όταν είναι λάθος</label>
    <label class="check"><input type="checkbox" data-setting="review_answers" ${state.quiz.settings.review_answers ? 'checked' : ''}> Review όλων των απαντήσεων στο τέλος</label>
    <label class="check"><input type="checkbox" data-setting="allow_restart" ${state.quiz.settings.allow_restart ? 'checked' : ''}> Κουμπί «Παίξτε ξανά» στο τέλος</label>
    <label class="check"><input type="checkbox" data-setting="show_pass_fail" ${state.quiz.settings.show_pass_fail ? 'checked' : ''}> Εμφάνιση Επιτυχία / Αποτυχία</label>
    <label>Βάση επιτυχίας<input type="number" step="0.5" data-setting="pass_score" value="${esc(state.quiz.settings.pass_score)}"></label>
  </div>
  <hr><h3>Ασφάλεια iframe και JavaScript Embed</h3><div class="settings-grid two">
    <label>Πολιτική embed<select data-setting="embed_mode"><option value="inherit" ${state.quiz.settings.embed_mode === 'inherit' ? 'selected' : ''}>Κληρονόμηση global whitelist</option><option value="public" ${state.quiz.settings.embed_mode === 'public' ? 'selected' : ''}>Ελεύθερο embed σε όλα τα domains</option><option value="restricted" ${state.quiz.settings.embed_mode === 'restricted' ? 'selected' : ''}>Μόνο τα domains αυτού του quiz</option></select></label>
    <label>Εγκεκριμένα domains<textarea rows="5" data-setting="embed_domains" placeholder="example.gr&#10;news.example.gr">${esc((state.quiz.settings.embed_domains || []).join('\n'))}</textarea><small>Ένα domain ανά γραμμή. Ισχύει όταν επιλέξετε «Μόνο τα domains αυτού του quiz».</small></label>
    <label class="settings-span-2">Προσαρμοσμένο μήνυμα απόρριψης<textarea rows="3" data-setting="embed_block_message" placeholder="Ωχ! Αυτό το quiz πήγε εκδρομή χωρίς άδεια…">${esc(state.quiz.settings.embed_block_message)}</textarea></label>
  </div>
  <div class="notice-inline">Η προγραμματισμένη δημοσίευση και η λήξη εκτελούνται μέσω WordPress Cron. Η whitelist ελέγχει iframe και JavaScript embeds· το shortcode στο ίδιο WordPress site παραμένει διαθέσιμο.</div></section>`;

  const themeTab = () => {
    const preset = state.quiz.theme.preset === 'custom'
      ? {...state.quiz.theme, label: 'Προσαρμοσμένο', description: 'Το προσωπικό χρωματικό style αυτού του quiz.'}
      : (quizThemePresets[state.quiz.theme.preset] || quizThemePresets.atelier);
    const options = `${state.quiz.theme.preset === 'custom' ? '<option value="custom" selected>Προσαρμοσμένο</option>' : ''}${Object.entries(quizThemePresets).map(([key, value]) => `<option value="${key}" ${state.quiz.theme.preset === key ? 'selected' : ''}>${esc(value.label)}</option>`).join('')}`;
    return `<section class="panel wpqs-theme-panel"><div class="section-heading"><div><h2>Εμφάνιση και αντίθεση</h2><p>Επιλέξτε έτοιμο style από dropdown ή προσαρμόστε κάθε χρώμα.</p></div><button data-theme-auto-contrast>Αυτόματη διόρθωση αντίθεσης</button></div>
      <div class="wpqs-theme-picker"><label>Έτοιμο style<select data-theme-preset-select>${options}</select></label><div class="wpqs-theme-preset-card" data-theme-preset-card style="--preset-primary:${esc(preset.primary)};--preset-secondary:${esc(preset.secondary)};--preset-page:${esc(preset.page)};--preset-bg:${esc(preset.background)};--preset-text:${esc(preset.text)};--preset-button:${esc(preset.button)};--preset-button-text:${esc(preset.button_text)}"><div><span></span><span></span><span></span><span></span></div><section><small>${esc(preset.label)}</small><strong>Προεπισκόπηση style</strong><p>${esc(preset.description)}</p><button>Έναρξη quiz</button></section></div></div>
      <div class="settings-grid two wpqs-theme-fields">
      ${colorField('primary', 'Κύριο χρώμα')}${colorField('secondary', 'Δευτερεύον χρώμα')}${colorField('page', 'Φόντο εξωτερικού χώρου')}${colorField('background', 'Φόντο κάρτας')}${colorField('text', 'Χρώμα κειμένου')}${colorField('muted', 'Δευτερεύον κείμενο')}${colorField('button', 'Χρώμα κουμπιού')}${colorField('button_text', 'Κείμενο κουμπιού')}${colorField('answer', 'Φόντο απάντησης')}${colorField('border', 'Περιγράμματα')}${colorField('correct', 'Σωστή απάντηση')}${colorField('wrong', 'Λάθος απάντηση')}
      <label>Στρογγυλοποίηση<input type="range" min="0" max="40" data-theme="radius" value="${esc(state.quiz.theme.radius)}"><span>${esc(state.quiz.theme.radius)}px</span></label>
      <label>Γραμματοσειρά<select data-theme="font"><option value="system" ${state.quiz.theme.font === 'system' ? 'selected' : ''}>Συστήματος</option><option value="serif" ${state.quiz.theme.font === 'serif' ? 'selected' : ''}>Με πατούρες</option><option value="rounded" ${state.quiz.theme.font === 'rounded' ? 'selected' : ''}>Στρογγυλεμένη</option></select></label>
      <label>Σκιά<select data-theme="shadow"><option value="none" ${state.quiz.theme.shadow === 'none' ? 'selected' : ''}>Χωρίς σκιά</option><option value="soft" ${state.quiz.theme.shadow === 'soft' ? 'selected' : ''}>Απαλή</option><option value="strong" ${state.quiz.theme.shadow === 'strong' ? 'selected' : ''}>Έντονη</option></select></label>
      </div><div class="contrast-report" data-contrast-report></div></section>`;
  };

  const colorField = (key, label) => `<label>${label}<span class="color-control"><input type="color" data-theme="${key}" value="${esc(state.quiz.theme[key])}"><input data-theme-text="${key}" value="${esc(state.quiz.theme[key])}"></span></label>`;

  const resultsTab = () => state.quiz.quiz_type === 'personality' ? personalityResultsTab() : scoreResultsTab();

  const scoreResultsTab = () => `<section class="panel"><div class="section-heading"><div><h2>Αποτελέσματα βάσει βαθμολογίας</h2><p>Εμφανίστε διαφορετική τελική οθόνη ανάλογα με τη συνολική βαθμολογία.</p></div><button class="wpqs-link" data-add-result>+ Νέο αποτέλεσμα</button></div><div class="result-list">${state.quiz.settings.results.map((range, index) => `<article class="result-card"><div class="result-head"><strong>Αποτέλεσμα ${index + 1}</strong><button class="wpqs-link danger" data-delete-result="${index}">Διαγραφή</button></div><div class="range-row"><label>Ελάχιστο<input type="number" step="0.5" data-result="min" data-index="${index}" value="${esc(range.min)}"></label><label>Μέγιστο<input type="number" step="0.5" data-result="max" data-index="${index}" value="${esc(range.max)}"></label></div><label>Τίτλος<input data-result="title" data-index="${index}" value="${esc(range.title)}"></label><label>Περιγραφή<textarea data-result="description" data-index="${index}">${esc(range.description)}</textarea></label><div class="media-row compact">${range.image_url ? `<img src="${esc(range.image_url)}" alt="">` : '<span>Χωρίς εικόνα αποτελέσματος</span>'}<button class="wpqs-link" data-media="result" data-index="${index}">Επιλογή εικόνας</button>${range.image_url ? `<button class="wpqs-link danger" data-remove-media="result" data-index="${index}">Αφαίρεση</button>` : ''}</div><div class="range-row"><label>Κείμενο CTA<input data-result="cta_label" data-index="${index}" value="${esc(range.cta_label)}"></label><label>URL CTA<input type="url" data-result="cta_url" data-index="${index}" value="${esc(range.cta_url)}"></label></div></article>`).join('') || '<div class="empty-panel">Δεν υπάρχουν ακόμη προσαρμοσμένα αποτελέσματα. Θα εμφανιστεί η βασική οθόνη ολοκλήρωσης.</div>'}</div></section>`;

  const personalityResultsTab = () => `<section class="panel"><div class="section-heading"><div><h2>Προφίλ Personality Test</h2><p>Κάθε απάντηση μπορεί να δίνει διαφορετικούς βαθμούς σε κάθε προφίλ. Το προφίλ με τη μεγαλύτερη βαθμολογία εμφανίζεται στο τέλος.</p></div><button class="wpqs-link" data-add-profile>+ Νέο προφίλ</button></div><label>Ισοβαθμία<select data-personality-setting="personality_tie_strategy"><option value="first" ${state.quiz.settings.personality_tie_strategy === 'first' ? 'selected' : ''}>Εμφάνιση πρώτου προφίλ</option><option value="all" ${state.quiz.settings.personality_tie_strategy === 'all' ? 'selected' : ''}>Καταγραφή όλων των ισόβαθμων</option></select></label><div class="result-list">${state.quiz.settings.personality_profiles.map((profile, index) => `<article class="result-card profile-card"><div class="result-head"><strong>${esc(profile.title || `Προφίλ ${index + 1}`)}</strong><button class="wpqs-link danger" data-delete-profile="${index}">Διαγραφή</button></div><div class="range-row"><label>Κλειδί<input data-profile="key" data-index="${index}" value="${esc(profile.key)}" placeholder="analytikos"></label><label>Τίτλος<input data-profile="title" data-index="${index}" value="${esc(profile.title)}"></label></div><label>Περιγραφή<textarea data-profile="description" data-index="${index}">${esc(profile.description)}</textarea></label><div class="media-row compact">${profile.image_url ? `<img src="${esc(profile.image_url)}" alt="">` : '<span>Χωρίς εικόνα προφίλ</span>'}<button class="wpqs-link" data-media="profile" data-index="${index}">Επιλογή εικόνας</button>${profile.image_url ? `<button class="wpqs-link danger" data-remove-media="profile" data-index="${index}">Αφαίρεση</button>` : ''}</div><div class="range-row"><label>Κείμενο CTA<input data-profile="cta_label" data-index="${index}" value="${esc(profile.cta_label)}"></label><label>URL CTA<input type="url" data-profile="cta_url" data-index="${index}" value="${esc(profile.cta_url)}"></label></div></article>`).join('') || '<div class="empty-panel">Προσθέστε τουλάχιστον δύο προφίλ. Μετά επιστρέψτε στις ερωτήσεις και δώστε βάρη προσωπικότητας στις απαντήσεις.</div>'}</div></section>`;

  const metricDelta = value => {
    const numeric = number(value);
    const sign = numeric > 0 ? '+' : '';
    return `<small class="metric-delta ${numeric > 0 ? 'up' : numeric < 0 ? 'down' : ''}">${sign}${numeric}% από την προηγούμενη περίοδο</small>`;
  };

  const trendSvg = rows => {
    const data = Array.isArray(rows) ? rows : [];
    if (!data.length) return '<div class="empty-panel">Δεν υπάρχουν δεδομένα για το επιλεγμένο διάστημα.</div>';
    const width = 760, height = 220, padding = 24;
    const max = Math.max(1, ...data.flatMap(row => [number(row.views), number(row.starts), number(row.completions)]));
    const points = key => data.map((row, index) => {
      const x = padding + (data.length === 1 ? 0 : index * ((width - padding * 2) / (data.length - 1)));
      const y = height - padding - (number(row[key]) / max) * (height - padding * 2);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
    return `<div class="wpqs-trend-chart"><svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Γράφημα επισκεψιμότητας"><line x1="${padding}" y1="${height-padding}" x2="${width-padding}" y2="${height-padding}" class="axis"/><polyline class="trend views" points="${points('views')}"/><polyline class="trend starts" points="${points('starts')}"/><polyline class="trend completions" points="${points('completions')}"/></svg><div class="trend-legend"><span class="views">Προβολές</span><span class="starts">Εκκινήσεις</span><span class="completions">Ολοκληρώσεις</span></div><div class="trend-labels"><span>${esc(data[0]?.day || '')}</span><span>${esc(data[data.length-1]?.day || '')}</span></div></div>`;
  };

  const distributionMarkup = (title, rows, note = '') => `<article class="analytics-distribution"><h3>${esc(title)}</h3>${note ? `<p>${esc(note)}</p>` : ''}<div>${(rows || []).slice(0, 8).map(row => `<div class="distribution-row"><span>${esc(row.label)}</span><div><i style="width:${Math.max(1, number(row.percent))}%"></i></div><b>${number(row.value)} <small>${number(row.percent)}%</small></b></div>`).join('') || '<div class="empty">Χωρίς δεδομένα</div>'}</div></article>`;

  const analyticsContent = (analytics, global = false) => {
    const comparison = analytics.comparison || {};
    const audience = analytics.audience || {};
    const overview = analytics.overview || analytics;
    return `<section class="analytics-panel analytics-pro">
      <div class="analytics-toolbar"><div><h2>${global ? 'Συνολικά Analytics' : 'Analytics quiz'}</h2><p>${esc(analytics.range?.from || '')} – ${esc(analytics.range?.to || '')}</p></div><div class="analytics-filters"><select data-analytics-preset><option value="7" ${state.analyticsPreset === '7' ? 'selected' : ''}>7 ημέρες</option><option value="30" ${state.analyticsPreset === '30' ? 'selected' : ''}>30 ημέρες</option><option value="90" ${state.analyticsPreset === '90' ? 'selected' : ''}>90 ημέρες</option><option value="custom" ${state.analyticsPreset === 'custom' ? 'selected' : ''}>Προσαρμοσμένο</option></select><input type="date" data-analytics-from value="${esc(state.analyticsFrom)}" ${state.analyticsPreset !== 'custom' ? 'hidden' : ''}><input type="date" data-analytics-to value="${esc(state.analyticsTo)}" ${state.analyticsPreset !== 'custom' ? 'hidden' : ''}><select data-analytics-group><option value="day" ${state.analyticsGroup === 'day' ? 'selected' : ''}>Ημέρα</option><option value="week" ${state.analyticsGroup === 'week' ? 'selected' : ''}>Εβδομάδα</option><option value="month" ${state.analyticsGroup === 'month' ? 'selected' : ''}>Μήνας</option></select><button data-analytics-refresh>Εφαρμογή</button><button data-analytics-export>Εξαγωγή CSV</button><button data-analytics-print>Εκτύπωση / PDF</button></div></div>
      <div class="analytics-cards analytics-overview"><article><span>Προβολές</span><b>${number(overview.views)}</b>${metricDelta(comparison.views)}</article><article><span>Εκκινήσεις</span><b>${number(overview.starts)}</b>${metricDelta(comparison.starts)}</article><article><span>Ολοκληρώσεις</span><b>${number(overview.completions)}</b>${metricDelta(comparison.completions)}</article><article><span>Completion rate</span><b>${number(overview.completion_rate)}%</b>${metricDelta(comparison.completion_rate)}</article><article><span>Μέση βαθμολογία</span><b>${number(overview.average_score)}</b>${metricDelta(comparison.average_score)}</article><article><span>Μέσος χρόνος</span><b>${number(overview.average_time)}s</b><small>${number(overview.abandoned)} εγκαταλείψεις</small></article></div>
      <div class="analytics-grid-main"><article class="panel analytics-trend"><div class="section-heading"><div><h3>Εξέλιξη περιόδου</h3><p>Προβολές, εκκινήσεις και ολοκληρώσεις.</p></div></div>${trendSvg(analytics.timeseries || analytics.daily || [])}</article><article class="panel analytics-funnel"><h3>Funnel</h3>${(analytics.funnel || []).map(item => `<div class="funnel-row"><div><span>${esc(item.label)}</span><b>${number(item.value)}</b></div><div><i style="width:${Math.max(1, number(item.rate))}%"></i></div><small>${number(item.rate)}%</small></div>`).join('')}</article></div>
      ${global && analytics.quiz_breakdown?.length ? `<article class="panel"><h3>Απόδοση ανά quiz</h3><div class="wpqs-table-scroll"><table><thead><tr><th>Quiz</th><th>Τύπος</th><th>Views</th><th>Starts</th><th>Completions</th><th>Rate</th></tr></thead><tbody>${analytics.quiz_breakdown.map(row => `<tr><td>${esc(row.title)}</td><td>${esc(quizTypeLabel(row.quiz_type))}</td><td>${row.views}</td><td>${row.starts}</td><td>${row.completions}</td><td>${row.completion_rate}%</td></tr>`).join('')}</tbody></table></div></article>` : ''}
      <div class="analytics-distributions">${distributionMarkup('Συσκευές', audience.devices)}${distributionMarkup('Browsers', audience.browsers)}${distributionMarkup('Λειτουργικά', audience.operating_systems)}${distributionMarkup('Referrers', audience.referrers)}${distributionMarkup('Χώρες', audience.countries, analytics.data_notes?.location || '')}${distributionMarkup('Πόλεις', audience.cities)}${distributionMarkup('UTM Source', audience.utm_sources)}${distributionMarkup('UTM Campaign', audience.utm_campaigns)}</div>
      <div class="analytics-distributions">${distributionMarkup('Αποτελέσματα', analytics.result_distribution)}${distributionMarkup('Κατανομή score', analytics.score_distribution)}${distributionMarkup('Επιτυχία / αποτυχία', analytics.pass_distribution)}</div>
      ${!global ? `<article class="panel"><h3>Απόδοση και drop-off ανά ερώτηση</h3><div class="wpqs-table-scroll"><table><thead><tr><th>#</th><th>Ερώτηση</th><th>Reached</th><th>Reach rate</th><th>Σωστό</th><th>Λάθος</th><th>Skip</th><th>Χρόνος</th><th>Drop-off</th></tr></thead><tbody>${(analytics.questions || []).map(row => `<tr><td>${number(row.position)+1}</td><td><strong>${esc(row.title)}</strong>${row.answer_distribution?.length ? `<details><summary>Απαντήσεις</summary>${row.answer_distribution.map(item => `<div>${esc(item.label)} — ${item.value} (${item.percent}%)</div>`).join('')}</details>` : ''}</td><td>${row.reached}</td><td>${row.reach_rate}%</td><td>${row.correct_percent}%</td><td>${row.wrong_percent}%</td><td>${row.skipped_percent}%</td><td>${row.average_time}s</td><td>${row.dropoff} (${row.dropoff_percent}%)</td></tr>`).join('') || '<tr><td colspan="9" class="empty">Δεν υπάρχουν ακόμη δεδομένα.</td></tr>'}</tbody></table></div></article>` : ''}
      <article class="panel"><h3>Πρόσφατες ολοκληρώσεις</h3><div class="wpqs-table-scroll"><table><thead><tr><th>Ημερομηνία</th><th>Score</th><th>Σωστές</th><th>Αποτέλεσμα</th><th>Pass</th><th>Χρόνος</th></tr></thead><tbody>${(analytics.latest_completions || []).map(row => `<tr><td>${esc(formatDate(row.completed_at))}</td><td>${row.score}${number(row.max_score) ? ` / ${row.max_score}` : ''}</td><td>${row.correct} / ${row.total}</td><td>${esc(row.result)}</td><td>${row.pass === true ? '✓' : row.pass === false ? '✕' : '—'}</td><td>${row.duration === null ? '—' : `${row.duration}s`}</td></tr>`).join('') || '<tr><td colspan="6" class="empty">Δεν υπάρχουν ολοκληρώσεις.</td></tr>'}</tbody></table></div></article>
      <div class="notice-inline">${esc(analytics.data_notes?.privacy || '')}</div>
    </section>`;
  };

  const csvCell = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
  const downloadAnalyticsCsv = (analytics, global = false) => {
    const overview = analytics?.overview || analytics || {};
    const rows = [
      ['Ενότητα', 'Πεδίο', 'Τιμή'],
      ['Overview', 'Προβολές', number(overview.views)],
      ['Overview', 'Εκκινήσεις', number(overview.starts)],
      ['Overview', 'Ολοκληρώσεις', number(overview.completions)],
      ['Overview', 'Completion rate', `${number(overview.completion_rate)}%`],
      ['Overview', 'Μέση βαθμολογία', number(overview.average_score)],
      ['Overview', 'Μέσος χρόνος', `${number(overview.average_time)}s`],
      ['Overview', 'Εγκαταλείψεις', number(overview.abandoned)],
      [], ['Χρονοσειρά', 'Ημερομηνία', 'Views', 'Starts', 'Completions', 'Shares'],
      ...(analytics?.timeseries || analytics?.daily || []).map(item => ['Χρονοσειρά', item.day, item.views, item.starts, item.completions, item.shares || 0])
    ];
    if (global) {
      rows.push([], ['Quiz', 'Τύπος', 'Views', 'Starts', 'Completions', 'Completion rate']);
      (analytics?.quiz_breakdown || []).forEach(item => rows.push([item.title, quizTypeLabel(item.quiz_type), item.views, item.starts, item.completions, `${item.completion_rate}%`]));
    } else {
      rows.push([], ['Ερώτηση', 'Reached', 'Reach rate', 'Correct %', 'Wrong %', 'Skipped %', 'Average time', 'Drop-off']);
      (analytics?.questions || []).forEach(item => rows.push([item.title, item.reached, `${item.reach_rate}%`, `${item.correct_percent}%`, `${item.wrong_percent}%`, `${item.skipped_percent}%`, `${item.average_time}s`, `${item.dropoff} (${item.dropoff_percent}%)`]));
    }
    const distributions = {
      'Συσκευές': analytics?.audience?.devices, 'Browsers': analytics?.audience?.browsers,
      'Λειτουργικά': analytics?.audience?.operating_systems, 'Referrers': analytics?.audience?.referrers,
      'Χώρες': analytics?.audience?.countries, 'Πόλεις': analytics?.audience?.cities,
      'UTM Source': analytics?.audience?.utm_sources, 'UTM Campaign': analytics?.audience?.utm_campaigns,
      'Αποτελέσματα': analytics?.result_distribution, 'Score': analytics?.score_distribution, 'Pass/Fail': analytics?.pass_distribution
    };
    Object.entries(distributions).forEach(([title, items]) => {
      rows.push([], [title, 'Πλήθος', 'Ποσοστό']);
      (items || []).forEach(item => rows.push([item.label, item.value, `${item.percent}%`]));
    });
    const csv = '\uFEFF' + rows.map(row => row.map(csvCell).join(';')).join('\r\n');
    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `wpqs-analytics-${global ? 'all' : state.quiz?.slug || state.quiz?.id || 'quiz'}-${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(link); link.click(); URL.revokeObjectURL(link.href); link.remove();
  };

  const bindAnalyticsEvents = (global = false) => {
    const rerender = () => global ? renderGlobalAnalytics() : renderBuilder();
    root.querySelector('[data-analytics-preset]')?.addEventListener('change', event => {
      state.analyticsPreset = event.target.value;
      rerender();
    });
    root.querySelector('[data-analytics-from]')?.addEventListener('change', event => { state.analyticsFrom = event.target.value; });
    root.querySelector('[data-analytics-to]')?.addEventListener('change', event => { state.analyticsTo = event.target.value; });
    root.querySelector('[data-analytics-group]')?.addEventListener('change', event => { state.analyticsGroup = event.target.value; });
    root.querySelector('[data-analytics-refresh]')?.addEventListener('click', () => global ? loadGlobalAnalytics() : loadAnalytics());
    root.querySelector('[data-analytics-export]')?.addEventListener('click', () => downloadAnalyticsCsv(global ? state.dashboardAnalytics : state.analytics, global));
    root.querySelector('[data-analytics-print]')?.addEventListener('click', () => window.print());
  };

  const renderGlobalAnalytics = () => {
    state.view = 'analytics-global';
    root.innerHTML = `<main class="wpqs-dashboard wpqs-dashboard-v2 wpqs-portal-page wpqs-global-analytics">${portalNav('analytics-global')}<header class="wpqs-dashboard-hero"><div><span class="wpqs-kicker">QUIZ ATELIER</span><h1>Συνολικά Analytics</h1><p>Συγκεντρωτική εικόνα όλων των quiz, του funnel και του κοινού.</p></div><button class="wpqs-ghost" data-analytics-back>← Επιστροφή στα quiz</button></header><section class="wpqs-global-analytics-body">${state.loadingPanel || !state.dashboardAnalytics ? '<div class="empty-panel">Φόρτωση analytics…</div>' : analyticsContent(state.dashboardAnalytics, true)}</section></main>`;
    bindPortalNav();
    root.querySelector('[data-analytics-back]').onclick = renderList;
    bindAnalyticsEvents(true);
  };

  const openGlobalAnalytics = () => {
    state.view = 'analytics-global';
    renderGlobalAnalytics();
    loadGlobalAnalytics();
  };

  const analyticsTab = () => {
    if (!state.quiz.id) return '<div class="empty-panel">Αποθηκεύστε πρώτα το quiz για να δείτε στατιστικά.</div>';
    if (state.loadingPanel || !state.analytics) return '<div class="empty-panel">Φόρτωση στατιστικών…</div>';
    return analyticsContent(state.analytics, false);
  };

  const workflowTab = () => {
    const quiz = state.quiz;
    if (!quiz.id) return '<div class="empty-panel">Αποθηκεύστε πρώτα το quiz για να χρησιμοποιήσετε το workflow.</div>';
    const isOwner = number(quiz.author_id) === number(state.me?.id);
    const canReview = bool(WPQS.canReview);
    const history = Array.isArray(quiz.review_history) ? quiz.review_history : [];
    const actions = [];
    if (isOwner && ['draft','changes_requested'].includes(quiz.workflow_status)) actions.push('<button class="wpqs-primary" data-workflow-action="submitted">Υποβολή για έλεγχο</button>');
    if (canReview) {
      actions.push('<button data-workflow-action="changes_requested">Ζητήστε αλλαγές</button>');
      actions.push('<button data-workflow-action="approved">Έγκριση</button>');
      if (WPQS.canPublish) actions.push('<button class="wpqs-primary" data-workflow-action="published">Έγκριση & Δημοσίευση</button>');
    }
    const workflowSteps = ['draft','submitted','approved','published'];
    const effectiveStatus = quiz.workflow_status === 'changes_requested' ? 'submitted' : quiz.workflow_status;
    const activeIndex = Math.max(0, workflowSteps.indexOf(effectiveStatus));
    const stepper = `<div class="wpqs-workflow-stepper">${workflowSteps.map((step,index)=>`<div class="${index < activeIndex ? 'is-complete' : index === activeIndex ? 'is-current' : ''}"><span>${index < activeIndex ? '✓' : index + 1}</span><small>${esc(workflowLabel(step))}</small></div>`).join('')}</div>`;
    return `<section class="panel wpqs-workflow-panel"><div class="wpqs-workflow-status-card"><div><span class="wpqs-kicker">ΤΡΕΧΟΥΣΑ ΚΑΤΑΣΤΑΣΗ</span><strong>${esc(workflowLabel(quiz.workflow_status))}</strong><p>Δημιουργός: ${esc(quiz.author_name || '—')} · Ορατότητα: ${esc(visibilityLabel(quiz.visibility_scope))}</p></div><div class="wpqs-workflow-actions">${actions.join('') || '<span class="empty">Δεν υπάρχει διαθέσιμη ενέργεια για τον ρόλο σας.</span>'}</div></div>${stepper}${quiz.workflow_status === 'changes_requested' ? '<div class="notice-inline wpqs-changes-requested"><strong>Ζητήθηκαν αλλαγές.</strong> Δείτε το τελευταίο σχόλιο στο ιστορικό, διορθώστε το quiz και υποβάλετέ το ξανά.</div>' : ''}<div class="wpqs-review-compose"><label>Σχόλιο προς την ομάδα<textarea data-workflow-comment placeholder="Γράψτε τι άλλαξε, τι χρειάζεται διόρθωση ή μία σημείωση έγκρισης"></textarea></label><button data-workflow-action="comment">Προσθήκη σχολίου</button></div><div><h2>Ιστορικό ελέγχου</h2><div class="wpqs-review-timeline">${history.map(item=>`<article class="wpqs-review-entry"><i></i><div><strong>${esc(workflowLabel(item.action) || activityLabel(`quiz_${item.action}`))}</strong><p>${esc(item.comment || 'Χωρίς σχόλιο')} · ${esc(item.display_name || 'Χρήστης')}</p></div><time>${esc(formatDate(item.created_at))}</time></article>`).join('') || '<div class="empty-panel">Δεν υπάρχει ακόμη δραστηριότητα workflow.</div>'}</div></div></section>`;
  };

  const bindWorkflowEvents = () => {
    root.querySelectorAll('[data-workflow-action]').forEach(button => button.onclick = async () => {
      const action = button.dataset.workflowAction;
      const comment = root.querySelector('[data-workflow-comment]')?.value || '';
      if (action === 'submitted' && !runBuilderValidation(true)) return;
      if (action === 'changes_requested' && !comment.trim()) return alert('Γράψτε ποιες αλλαγές ζητάτε.');
      button.disabled = true;
      try {
        state.quiz = normaliseQuiz(await api(`quizzes/${state.quiz.id}/workflow`, {method:'POST', body:JSON.stringify({action, comment})}));
        state.quizzes = state.quizzes.map(item => number(item.id) === number(state.quiz.id) ? {...item, ...state.quiz} : item);
        toast(action === 'comment' ? 'Το σχόλιο προστέθηκε' : 'Το workflow ενημερώθηκε');
        renderBuilder();
      } catch (error) { button.disabled = false; alert(error.message); }
    });
  };

  const historyTab = () => {
    if (!state.quiz.id) return '<div class="empty-panel">Αποθηκεύστε πρώτα το quiz για να χρησιμοποιήσετε το ιστορικό.</div>';
    if (state.loadingPanel || !state.revisions) return '<div class="empty-panel">Φόρτωση ιστορικού…</div>';
    return `<section class="panel"><h2>Ιστορικό εκδόσεων</h2><p>Οι χειροκίνητες αποθηκεύσεις και δημοσιεύσεις δημιουργούν έκδοση. Η αυτόματη αποθήκευση όχι.</p><div class="revision-list">${state.revisions.map(revision => `<article><div><strong>Έκδοση ${revision.version_number}</strong><small>${esc(formatDate(revision.created_at))}</small></div><button data-restore="${revision.id}">Επαναφορά</button></article>`).join('') || '<div class="empty-panel">Δεν υπάρχουν ακόμη εκδόσεις. Κάντε χειροκίνητη αποθήκευση.</div>'}</div></section>`;
  };

  const resetPreview = () => {
    state.preview = {started: false, index: 0, responses: {}, orderSeeds: {}, feedback: null, complete: false};
  };

  const previewHasResponse = value => Array.isArray(value) ? value.length > 0 : value && typeof value === 'object' ? Object.keys(value).length > 0 : String(value ?? '').trim() !== '';
  const previewConditionSatisfied = question => {
    const condition = question.settings?.condition;
    if (!condition?.enabled) return true;
    const rules = Array.isArray(condition.rules) ? condition.rules : [];
    if (!rules.length) return true;
    const checks = rules.map(rule => {
      const response = state.preview.responses[String(rule.question_key || '')];
      const values = Array.isArray(response) ? response.map(String) : [String(response ?? '')];
      const answered = previewHasResponse(response);
      if (rule.operator === 'answered') return answered;
      if (rule.operator === 'not_answered') return !answered;
      if (rule.operator === 'not_equals') return !values.includes(String(rule.answer_key || ''));
      return values.includes(String(rule.answer_key || ''));
    });
    return condition.match === 'any' ? checks.some(Boolean) : checks.every(Boolean);
  };

  const previewFindIndex = from => {
    for (let index = Math.max(0, from); index < state.quiz.questions.length; index += 1) {
      if (previewConditionSatisfied(state.quiz.questions[index])) return index;
    }
    return -1;
  };

  const previewNormaliseText = (value, settings = {}) => {
    let output = String(value ?? '').trim();
    if (!settings.text_case_sensitive) output = output.toLocaleLowerCase('el');
    if (settings.text_ignore_accents !== false) output = output.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    if (settings.text_ignore_punctuation !== false) output = output.replace(/[^\p{L}\p{N}\s.-]/gu, '');
    return output.replace(/\s+/g, ' ').trim();
  };

  const previewEvaluationFor = (question, response) => {
    const type = question.type;
    const settings = question.settings || {};
    if (state.quiz.quiz_type === 'personality' || type === 'poll') return {gradable:false, correct:null, score:0, max:0};
    let correct = false, score = 0, max = Math.max(0, number(settings.points, 1));

    if (['multiple_choice','true_false','image_choice'].includes(type)) {
      const answer = question.answers.find(item => String(item.content.key) === String(response));
      const correctAnswer = question.answers.find(item => item.is_correct);
      max = Math.max(0, number(correctAnswer?.score, max));
      correct = Boolean(answer?.is_correct);
      score = correct ? Math.max(0, number(answer?.score, max)) : 0;
    } else if (type === 'multiple_answers') {
      const selected = [...(Array.isArray(response) ? response : [])].map(String).sort();
      const correctAnswers = question.answers.filter(item => item.is_correct);
      const expected = correctAnswers.map(item => String(item.content.key)).sort();
      max = correctAnswers.reduce((total,item)=>total+Math.max(0,number(item.score)),0) || Math.max(0,number(settings.points,1));
      correct = selected.length === expected.length && selected.every((value,index) => value === expected[index]);
      if (settings.multiple_scoring === 'partial' && selected.length) {
        const share = expected.length ? max / expected.length : 0;
        score = selected.reduce((total,key)=>total+(expected.includes(key) ? (Math.max(0,number(correctAnswers.find(item=>String(item.content.key)===key)?.score)) || share) : -share),0);
        score = Math.min(max,Math.max(0,score));
      } else score = correct ? max : 0;
    } else if (type === 'open_text') {
      const value = previewNormaliseText(response,settings);
      const accepted = question.answers.filter(item => item.is_correct);
      correct = accepted.some(item => previewNormaliseText(item.content.text,settings) === value && value !== '');
      max = Math.max(0,...accepted.map(item=>number(item.score)),number(settings.points,1));
      score = correct ? max : 0;
    } else if (type === 'numeric') {
      correct = response !== '' && Math.abs(number(response) - number(settings.numeric_answer)) <= Math.max(0, number(settings.numeric_tolerance));
      score = correct ? max : 0;
    } else if (['slider','rating'].includes(type)) {
      const value = number(response);
      correct = value >= number(settings.correct_min) && value <= number(settings.correct_max);
      score = correct ? max : 0;
    } else if (['ordering','ranking'].includes(type)) {
      const expected = question.answers.map(answer => String(answer.content.key));
      const selected = Array.isArray(response) ? response.map(String) : [];
      correct = selected.length === expected.length && selected.every((value,index) => value === expected[index]);
      if (settings.order_scoring === 'partial' && expected.length) {
        score = selected.reduce((total,value,index)=>total+(value===expected[index]?1:0),0) / expected.length * max;
      } else score = correct ? max : 0;
    } else if (type === 'matching') {
      const matches = question.answers.reduce((total,answer)=>total+(String(response?.[answer.content.key] || '') === String(answer.content.match_text || '') ? 1 : 0),0);
      correct = question.answers.length > 0 && matches === question.answers.length;
      score = settings.matching_scoring === 'partial' && question.answers.length ? matches / question.answers.length * max : correct ? max : 0;
    }
    return {gradable:true, correct, score:Number(score.toFixed(2)), max:Number(max.toFixed(2))};
  };

  const previewFeedbackFor = (question, response) => {
    const evaluation = previewEvaluationFor(question,response);
    if (!evaluation.gradable) return {...evaluation, heading:'Η απάντησή σας καταχωρήθηκε.'};
    return {...evaluation, heading:evaluation.correct ? 'Σωστά!' : 'Λάθος απάντηση'};
  };

  const previewResult = () => {
    let correct = 0, total = 0, score = 0, max = 0;
    state.quiz.questions.forEach(question => {
      if (!previewConditionSatisfied(question)) return;
      const response = state.preview.responses[String(question.settings.key)];
      const result = previewEvaluationFor(question,response);
      if (!result.gradable) return;
      total += 1;
      max += result.max;
      score += result.score;
      if (result.correct) correct += 1;
    });
    score=Number(score.toFixed(2)); max=Number(max.toFixed(2));
    const range = (state.quiz.settings.results || []).find(item => score >= number(item.min) && score <= number(item.max));
    return {correct,total,score,max,range};
  };

  const previewAnswerLabel = (question, key) => question.answers.find(answer => String(answer.content.key) === String(key))?.content?.text || '';

  const previewMarkup = () => {
    const quiz = state.quiz;
    const font = quiz.theme.font === 'serif' ? 'Georgia,serif' : quiz.theme.font === 'rounded' ? 'ui-rounded,system-ui,sans-serif' : 'system-ui,sans-serif';
    const category = state.categories.find(item => number(item.id) === number(quiz.category_id));
    const style = `--preview-primary:${esc(quiz.theme.primary)};--preview-secondary:${esc(quiz.theme.secondary)};--preview-page:${esc(quiz.theme.page)};--preview-bg:${esc(quiz.theme.background)};--preview-text:${esc(quiz.theme.text)};--preview-muted:${esc(quiz.theme.muted)};--preview-button:${esc(quiz.theme.button)};--preview-button-text:${esc(quiz.theme.button_text)};--preview-border:${esc(quiz.theme.border)};--preview-correct:${esc(quiz.theme.correct)};--preview-wrong:${esc(quiz.theme.wrong)};--preview-radius:${esc(quiz.theme.radius)}px;font-family:${font}`;
    if (!state.preview.started) {
      return `<section id="preview-card" class="wpqs-preview-interactive wpqs-preview-intro" style="${style}">${quiz.settings.intro.image_url ? `<img class="preview-image" src="${esc(quiz.settings.intro.image_url)}" alt="">` : ''}${category ? `<span class="preview-category">${esc(category.name)}</span>` : ''}<small class="wpqs-preview-quiz-title">${esc(quiz.title || 'Νέο quiz')}</small><h2>${esc(quiz.settings.intro.title || quiz.title)}</h2>${quiz.settings.intro.subtitle ? `<p class="wpqs-preview-subtitle">${esc(quiz.settings.intro.subtitle)}</p>` : ''}<p>${esc(quiz.description)}</p><button type="button" data-preview-start>${esc(quiz.settings.intro.button || 'Έναρξη quiz')}</button>${!quiz.questions.length ? '<p class="wpqs-preview-warning">Προσθέστε τουλάχιστον μία ερώτηση.</p>' : ''}</section>`;
    }
    if (state.preview.complete) {
      const result = previewResult();
      return `<section id="preview-card" class="wpqs-preview-interactive wpqs-preview-result" style="${style}"><span class="wpqs-preview-complete-icon">✓</span><small>ΠΡΟΕΠΙΣΚΟΠΗΣΗ ΑΠΟΤΕΛΕΣΜΑΤΟΣ</small><h2>${esc(result.range?.title || 'Ολοκληρώθηκε!')}</h2><p>${esc(result.range?.description || `Απαντήσατε σωστά σε ${result.correct} από ${result.total} ερωτήσεις.`)}</p><strong class="wpqs-preview-score">Βαθμολογία: ${result.score}${result.max > 0 ? ` / ${result.max}` : ''}</strong><button type="button" data-preview-restart>Παίξτε ξανά</button></section>`;
    }
    const index = previewFindIndex(state.preview.index);
    if (index < 0) { state.preview.complete = true; return previewMarkup(); }
    state.preview.index = index;
    const question = quiz.questions[index];
    const response = state.preview.responses[String(question.settings.key)];
    const progress = quiz.questions.length ? Math.round(((index + 1) / quiz.questions.length) * 100) : 0;
    if (state.preview.feedback) {
      const correctAnswers = (() => {
        if (['ordering','ranking'].includes(question.type)) return question.answers.map(answer => answer.content.text).filter(Boolean);
        if (question.type === 'matching') return question.answers.map(answer => `${answer.content.text} → ${answer.content.match_text}`).filter(Boolean);
        if (question.type === 'numeric') return [String(question.settings.numeric_answer)];
        if (['slider','rating'].includes(question.type)) return [`${question.settings.correct_min} – ${question.settings.correct_max}`];
        return question.answers.filter(answer => answer.is_correct).map(answer => answer.content.text).filter(Boolean);
      })();
      return `<section id="preview-card" class="wpqs-preview-interactive wpqs-preview-feedback ${state.preview.feedback.correct ? 'is-correct' : 'is-wrong'}" style="${style}"><div class="wpqs-preview-progress"><i style="width:${progress}%"></i></div><small>ΕΡΩΤΗΣΗ ${index + 1} ΑΠΟ ${quiz.questions.length}</small><h2>${esc(state.preview.feedback.heading)}</h2>${!state.preview.feedback.correct && state.preview.feedback.gradable && correctAnswers.length ? `<div class="wpqs-preview-correct-answer"><strong>Σωστή απάντηση</strong><span>${correctAnswers.map(esc).join(', ')}</span></div>` : ''}${question.settings.explanation ? `<div class="wpqs-preview-explanation"><strong>Επεξήγηση</strong><p>${esc(question.settings.explanation)}</p></div>` : ''}<button type="button" data-preview-next>Συνέχεια</button></section>`;
    }
    let answers = '';
    if (['multiple_choice','true_false','image_choice','poll'].includes(question.type)) {
      answers = `<div class="wpqs-preview-options">${question.answers.map(answer => `<button type="button" data-preview-choice="${esc(answer.content.key)}">${answer.content.image_url ? `<img src="${esc(answer.content.image_url)}" alt="">` : ''}<span>${esc(answer.content.text || 'Απάντηση')}</span></button>`).join('')}</div>`;
    } else if (question.type === 'multiple_answers') {
      const selected = Array.isArray(response) ? response : [];
      answers = `<div class="wpqs-preview-options is-multiple">${question.answers.map(answer => `<label><input type="checkbox" data-preview-multiple value="${esc(answer.content.key)}" ${selected.includes(answer.content.key) ? 'checked' : ''}><span>${esc(answer.content.text || 'Απάντηση')}</span></label>`).join('')}</div><button type="button" data-preview-submit>Συνέχεια</button>`;
    } else if (question.type === 'open_text') {
      answers = `<textarea data-preview-open rows="4" placeholder="Γράψτε την απάντησή σας">${esc(response || '')}</textarea><button type="button" data-preview-submit>Συνέχεια</button>`;
    } else if (question.type === 'numeric') {
      answers = `<input type="number" step="any" data-preview-number value="${esc(response ?? '')}" placeholder="0"><button type="button" data-preview-submit>Συνέχεια</button>`;
    } else if (question.type === 'slider') {
      const min=number(question.settings.slider_min), max=number(question.settings.slider_max,100), value=response === undefined ? min : number(response);
      answers = `<div class="wpqs-preview-slider"><output>${value}</output><input type="range" data-preview-slider min="${min}" max="${max}" step="${Math.max(.01,number(question.settings.slider_step,1))}" value="${value}"></div><button type="button" data-preview-submit>Συνέχεια</button>`;
    } else if (question.type === 'rating') {
      const max=Math.max(2,Math.min(20,number(question.settings.rating_max,5)));
      const stars=question.settings.rating_style !== 'numbers';
      answers = `<div class="wpqs-preview-rating ${stars ? 'is-stars' : ''}">${Array.from({length:max},(_,i)=>`<button type="button" data-preview-rating="${i+1}" class="${number(response)===i+1?'is-selected':''}" aria-label="${i+1}">${stars ? '★' : i+1}</button>`).join('')}</div><button type="button" data-preview-submit>Συνέχεια</button>`;
    } else if (['ordering','ranking'].includes(question.type)) {
      const questionKey=String(question.settings.key);
      if (!Array.isArray(state.preview.orderSeeds[questionKey])) state.preview.orderSeeds[questionKey]=shuffled(question.answers.map(answer=>answer.content.key));
      const order=Array.isArray(response)&&response.length?response:state.preview.orderSeeds[questionKey];
      answers = `<div class="wpqs-preview-order">${order.map((key,position)=>`<div draggable="true" data-preview-order-item="${position}"><span>☰ ${position+1}. ${esc(previewAnswerLabel(question,key))}</span><button type="button" data-preview-order="${position}" data-direction="-1">↑</button><button type="button" data-preview-order="${position}" data-direction="1">↓</button></div>`).join('')}</div><button type="button" data-preview-submit>Συνέχεια</button>`;
    } else if (question.type === 'matching') {
      const matches=response&&typeof response==='object'?response:{};
      answers = `<div class="wpqs-preview-matching">${question.answers.map(answer=>`<label><span>${esc(answer.content.text)}</span><select data-preview-match="${esc(answer.content.key)}"><option value="">— Επιλέξτε —</option>${question.answers.map(option=>`<option value="${esc(option.content.match_text)}" ${matches[answer.content.key]===option.content.match_text?'selected':''}>${esc(option.content.match_text)}</option>`).join('')}</select></label>`).join('')}</div><button type="button" data-preview-submit>Συνέχεια</button>`;
    }
    return `<section id="preview-card" class="wpqs-preview-interactive wpqs-preview-question" style="${style}"><div class="wpqs-preview-progress"><i style="width:${progress}%"></i></div><small>ΕΡΩΤΗΣΗ ${index + 1} ΑΠΟ ${quiz.questions.length}</small><h2>${esc(question.content.title || 'Νέα ερώτηση')}</h2>${question.settings.hint ? `<details><summary>Βοήθεια</summary><p>${esc(question.settings.hint)}</p></details>` : ''}${answers}${question.settings.required === false ? '<button type="button" class="wpqs-preview-skip" data-preview-skip>Παράλειψη</button>' : ''}</section>`;
  };

  const previewAdvance = () => {
    state.preview.feedback = null;
    const next = previewFindIndex(state.preview.index + 1);
    if (next < 0) state.preview.complete = true;
    else state.preview.index = next;
    refreshPreview();
  };

  const previewSubmit = (surface, response) => {
    const question = state.quiz.questions[state.preview.index];
    if (!question) return;
    const required = question.settings.required !== false;
    if (required && !previewHasResponse(response)) { toast('Δώστε μία απάντηση για να συνεχίσετε.'); return; }
    state.preview.responses[String(question.settings.key)] = response;
    if (state.quiz.settings.show_feedback !== false) state.preview.feedback = previewFeedbackFor(question, response);
    else previewAdvance();
    refreshPreview();
  };

  const bindPreviewEvents = () => {
    root.querySelectorAll('[data-preview-surface]').forEach(surface => {
      surface.querySelector('[data-preview-start]')?.addEventListener('click', () => { state.preview.started=true; state.preview.index=previewFindIndex(0); state.preview.complete=state.preview.index<0; refreshPreview(); });
      surface.querySelector('[data-preview-restart]')?.addEventListener('click', () => { resetPreview(); refreshPreview(); });
      surface.querySelector('[data-preview-next]')?.addEventListener('click', previewAdvance);
      surface.querySelector('[data-preview-skip]')?.addEventListener('click', () => previewSubmit(surface, ''));
      surface.querySelectorAll('[data-preview-choice]').forEach(button => button.addEventListener('click', () => previewSubmit(surface, button.dataset.previewChoice)));
      surface.querySelectorAll('[data-preview-rating]').forEach(button => button.addEventListener('click', () => { state.preview.responses[String(state.quiz.questions[state.preview.index].settings.key)] = number(button.dataset.previewRating); refreshPreview(); }));
      surface.querySelector('[data-preview-slider]')?.addEventListener('input', event => { event.target.previousElementSibling.textContent=event.target.value; state.preview.responses[String(state.quiz.questions[state.preview.index].settings.key)] = number(event.target.value); });
      surface.querySelectorAll('[data-preview-order]').forEach(button => button.addEventListener('click', () => {
        const question=state.quiz.questions[state.preview.index]; const key=String(question.settings.key); const order=Array.isArray(state.preview.responses[key])&&state.preview.responses[key].length?[...state.preview.responses[key]]:question.answers.map(answer=>answer.content.key); const index=number(button.dataset.previewOrder); const target=index+number(button.dataset.direction); if(target<0||target>=order.length)return; [order[index],order[target]]=[order[target],order[index]]; state.preview.responses[key]=order; refreshPreview();
      }));
      surface.querySelector('[data-preview-submit]')?.addEventListener('click', () => {
        const question=state.quiz.questions[state.preview.index]; let response='';
        if(question.type==='multiple_answers') response=[...surface.querySelectorAll('[data-preview-multiple]:checked')].map(input=>input.value);
        else if(question.type==='open_text') response=surface.querySelector('[data-preview-open]')?.value||'';
        else if(question.type==='numeric') response=surface.querySelector('[data-preview-number]')?.value||'';
        else if(question.type==='slider') response=number(surface.querySelector('[data-preview-slider]')?.value);
        else if(question.type==='rating') response=state.preview.responses[String(question.settings.key)]||'';
        else if(['ordering','ranking'].includes(question.type)) response=state.preview.responses[String(question.settings.key)]||state.preview.orderSeeds[String(question.settings.key)]||question.answers.map(answer=>answer.content.key);
        else if(question.type==='matching') { response={}; surface.querySelectorAll('[data-preview-match]').forEach(select=>{response[select.dataset.previewMatch]=select.value;}); }
        previewSubmit(surface,response);
      });
    });
    root.querySelectorAll('[data-preview-reset]').forEach(button => button.onclick=()=>{resetPreview();refreshPreview();});
  };

  const openBuilderPreviewModal = () => {
    resetPreview();
    const modal=document.createElement('div'); modal.className='wpqs-modal-backdrop wpqs-builder-preview-modal';
    modal.innerHTML=`<section class="wpqs-modal wpqs-playable-preview-modal" role="dialog" aria-modal="true"><header class="wpqs-modal-head"><div><span class="wpqs-kicker">PLAYABLE PREVIEW</span><h2>${esc(state.quiz.title||'Νέο quiz')}</h2><p>Δοκιμάστε το quiz όπως θα το δει ο επισκέπτης, ακόμη και πριν αποθηκευτεί.</p></div><button type="button" class="wpqs-modal-close" data-preview-modal-close aria-label="Κλείσιμο">×</button></header><div class="wpqs-playable-preview-stage"><div data-preview-surface>${previewMarkup()}</div></div></section>`;
    root.appendChild(modal); ensureButtonTypes(modal); bindPreviewEvents();
    const close=()=>modal.remove(); modal.querySelector('[data-preview-modal-close]').onclick=close; modal.addEventListener('click',event=>{if(event.target===modal)close();});
  };


  const openValidationIssue = issue => {
    if (!issue) return;
    state.tab = 'questions';
    if (issue.questionIndex !== null && state.quiz.questions[issue.questionIndex]) state.activeQuestionKey = String(state.quiz.questions[issue.questionIndex].settings.key || '');
    renderBuilder();
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const target = issue.selector ? root.querySelector(issue.selector) : null;
      (target || root.querySelector('.wpqs-validation-summary'))?.scrollIntoView({behavior:'smooth', block:'center'});
      target?.focus?.({preventScroll:true});
    }));
  };

  const runBuilderValidation = (publishing = true) => {
    state.validationIssues = collectValidationIssues(state.quiz, publishing);
    if (!state.validationIssues.length) { toast('Ο έλεγχος ολοκληρώθηκε χωρίς σφάλματα.'); return true; }
    state.tab = 'questions';
    const first = state.validationIssues[0];
    if (first.questionIndex !== null && state.quiz.questions[first.questionIndex]) state.activeQuestionKey = String(state.quiz.questions[first.questionIndex].settings.key || '');
    renderBuilder();
    requestAnimationFrame(() => root.querySelector('.wpqs-validation-summary')?.scrollIntoView({behavior:'smooth',block:'start'}));
    toast(`Βρέθηκαν ${state.validationIssues.length} σημεία που χρειάζονται διόρθωση.`);
    return false;
  };

  const bindValidationEvents = () => {
    root.querySelectorAll('[data-validate]').forEach(button => button.onclick = () => runBuilderValidation(true));
    root.querySelectorAll('[data-validation-jump]').forEach(button => button.onclick = () => openValidationIssue(state.validationIssues[number(button.dataset.validationJump)]));
  };

  const bindBuilder = () => {
    const quiz = state.quiz;
    root.querySelector('[data-back]').onclick = () => load();
    root.querySelector('[data-conflict-reload]')?.addEventListener('click', async () => {
      try {
        const latest = state.conflict?.latest || await api(`quizzes/${quiz.id}`);
        removeRecovery(state.quiz);
        state.quiz = normaliseQuiz(latest); state.conflict = null; state.dirty = false; state.validationIssues = [];
        toast('Φορτώθηκε η νεότερη έκδοση από τον server'); renderBuilder();
      } catch (error) { toast(error.message); }
    });
    root.querySelector('[data-conflict-copy]')?.addEventListener('click', async () => {
      const copy = clone(state.quiz);
      delete copy.id; delete copy.created_at; delete copy.updated_at; copy.status = 'draft'; copy.workflow_status = 'draft'; copy.visibility_scope = 'personal'; copy.title = `${copy.title} (Αντίγραφο)`;
      copy.questions.forEach(question => { delete question.id; delete question.quiz_id; question.answers.forEach(answer => { delete answer.id; delete answer.question_id; }); });
      try { const saved = normaliseQuiz(await api('quizzes', {method:'POST', body:JSON.stringify(copy)})); state.conflict = null; state.quiz = saved; state.dirty = false; toast('Οι αλλαγές αποθηκεύτηκαν ως νέο αντίγραφο'); renderBuilder(); } catch (error) { toast(error.message); }
    });
    root.querySelectorAll('[data-save]').forEach(button => button.onclick = () => save(null, false));
    root.querySelectorAll('[data-publish]').forEach(button => button.addEventListener('click', () => save('published', false)));
    root.querySelectorAll('[data-preview]').forEach(button => button.addEventListener('click', openBuilderPreviewModal));
    root.querySelector('[data-builder-embed]')?.addEventListener('click', () => openEmbedModal(quiz));
    root.querySelector('[data-save-template]')?.addEventListener('click', async () => {
      const title = prompt('Τίτλος template', quiz.title);
      if (!title) return;
      const scope = WPQS.canManageUniversal && confirm('Πατήστε OK για Universal template ή Ακύρωση για template του Organization.') ? 'universal' : 'organization';
      try {
        const template = await api('templates', {method:'POST', body:JSON.stringify({quiz_id:quiz.id,title,description:quiz.description,scope})});
        state.templates.unshift(template); toast('Το template αποθηκεύτηκε');
      } catch (error) { alert(error.message); }
    });
    root.querySelector('.quiz-title').oninput = event => { quiz.title = event.target.value; const introTitleField=root.querySelector('[data-field="title"]'); if(introTitleField&&introTitleField!==event.target) introTitleField.value=event.target.value; markDirty(); refreshPreview(); };
    root.querySelector('[data-user-style-open]')?.addEventListener('click', openUserStyleModal);
    root.querySelectorAll('[data-tab]').forEach(button => button.onclick = () => {
      state.tab = button.dataset.tab; renderBuilder();
      if (state.tab === 'analytics') loadAnalytics();
      if (state.tab === 'history') loadRevisions();
    });

    bindQuestionEvents();
    bindValidationEvents();
    bindBankEvents();
    bindCategoryEvents();
    bindSettingsEvents();
    bindThemeEvents();
    bindResultEvents();
    bindWorkflowEvents();
    bindMediaEvents();
    bindAnalyticsEvents(false);

    root.querySelectorAll('[data-restore]').forEach(button => button.onclick = async () => {
      if (!confirm('Να γίνει επαναφορά αυτής της έκδοσης; Η τρέχουσα έκδοση θα αποθηκευτεί πρώτα.')) return;
      try { state.quiz = normaliseQuiz(await api(`quizzes/${quiz.id}/revisions/${button.dataset.restore}/restore`, {method: 'POST', body: '{}'})); state.dirty = false; toast('Η έκδοση επαναφέρθηκε'); renderBuilder(); }
      catch (error) { alert(error.message); }
    });
  };

  const bindQuestionEvents = () => {
    const quiz = state.quiz;
    root.querySelectorAll('[data-question-toggle]').forEach(button => button.onclick = event => {
      event.preventDefault();
      const index = number(button.dataset.questionToggle); const question = quiz.questions[index]; if (!question) return;
      state.activeQuestionKey = state.activeQuestionKey === String(question.settings.key) ? '' : String(question.settings.key);
      preserveBuilderPosition(renderBuilder, `[data-question-card="${index}"]`);
    });
    root.querySelectorAll('[data-question-drag]').forEach(handle => {
      handle.ondragstart = event => { state.dragQuestionIndex = number(handle.dataset.questionDrag); event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', String(state.dragQuestionIndex)); handle.closest('.question-card')?.classList.add('is-dragging'); };
      handle.ondragend = () => { state.dragQuestionIndex = null; root.querySelectorAll('.question-card').forEach(card => card.classList.remove('is-dragging','is-drag-over')); };
    });
    root.querySelectorAll('[data-question-card]').forEach(card => {
      card.ondragover = event => { if (state.dragQuestionIndex === null) return; event.preventDefault(); card.classList.add('is-drag-over'); };
      card.ondragleave = () => card.classList.remove('is-drag-over');
      card.ondrop = event => { if (state.dragQuestionIndex === null) return; event.preventDefault(); const target = number(card.dataset.questionCard); const source = state.dragQuestionIndex; card.classList.remove('is-drag-over'); if (source === target) return; const [moved] = quiz.questions.splice(source,1); quiz.questions.splice(target,0,moved); state.activeQuestionKey = String(moved.settings.key); state.dragQuestionIndex = null; markDirty(); renderBuilder(); };
    });
    root.querySelectorAll('[data-answer-drag]').forEach(handle => {
      handle.ondragstart = event => { state.dragAnswer = {question:number(handle.dataset.question), answer:number(handle.dataset.answer)}; event.dataTransfer.effectAllowed='move'; event.dataTransfer.setData('text/plain', `${handle.dataset.question}:${handle.dataset.answer}`); handle.closest('.answer-row')?.classList.add('is-dragging'); };
      handle.ondragend = () => { state.dragAnswer = null; root.querySelectorAll('.answer-row').forEach(row => row.classList.remove('is-dragging','is-drag-over')); };
    });
    root.querySelectorAll('[data-answer-row]').forEach(row => {
      row.ondragover = event => { if (!state.dragAnswer || state.dragAnswer.question !== number(row.dataset.questionIndex)) return; event.preventDefault(); row.classList.add('is-drag-over'); };
      row.ondragleave = () => row.classList.remove('is-drag-over');
      row.ondrop = event => { if (!state.dragAnswer) return; event.preventDefault(); const questionIndex=number(row.dataset.questionIndex); if (state.dragAnswer.question !== questionIndex) return; const target=number(row.dataset.answerRow); const source=state.dragAnswer.answer; if(source===target)return; const answers=quiz.questions[questionIndex].answers; const [moved]=answers.splice(source,1); answers.splice(target,0,moved); state.dragAnswer=null; markDirty(); refreshQuestionCard(questionIndex); };
    });
    const setField = (path, value) => {
      if (path === 'title') { quiz.title = value; const topTitle=root.querySelector('.quiz-title'); if(topTitle&&topTitle.value!==value) topTitle.value=value; }
      if (path === 'description') quiz.description = value;
      if (path === 'intro.title') quiz.settings.intro.title = value;
      if (path === 'intro.subtitle') quiz.settings.intro.subtitle = value;
      if (path === 'intro.button') quiz.settings.intro.button = value;
      markDirty(); refreshPreview();
    };
    root.querySelectorAll('[data-field]').forEach(input => input.oninput = event => setField(input.dataset.field, event.target.value));
    root.querySelectorAll('[data-question-settings]').forEach(details => details.ontoggle = () => {
      const index = number(details.dataset.questionSettings);
      if (details.open) state.openQuestionSettings = index;
      else if (state.openQuestionSettings === index) state.openQuestionSettings = null;
    });
    root.querySelectorAll('[data-add-question]').forEach(addQuestionButton => {
      addQuestionButton.onclick = event => {
        event.preventDefault();
        event.stopPropagation();
        quiz.questions.push(newQuestion());
        state.openQuestionSettings = null;
        const index = quiz.questions.length - 1;
        state.activeQuestionKey = String(quiz.questions[index].settings.key || '');
        markDirty();
        preserveBuilderPosition(renderBuilder, `[data-question-title="${index}"]`);
      };
    });
    root.querySelectorAll('[data-question-title]').forEach(input => input.oninput = event => { const index=number(input.dataset.questionTitle); quiz.questions[index].content.title = event.target.value; const summary=input.closest('.question-card')?.querySelector('.wpqs-question-summary strong'); if(summary) summary.textContent=event.target.value.trim()||'Χωρίς τίτλο'; markDirty(); refreshPreview(); });
    root.querySelectorAll('[data-question-type]').forEach(select => select.onchange = event => {
      event.preventDefault();
      event.stopPropagation();
      const index = number(select.dataset.questionType);
      const nextType = String(event.target.value || 'multiple_choice');
      const question = quiz.questions[index];
      if (!question || !questionTypes.includes(nextType)) {
        toast('Ο τύπος ερώτησης δεν είναι διαθέσιμος.');
        return;
      }
      configureQuestionType(question, nextType);
      markDirty();
      refreshQuestionCard(index, `[data-question-type="${index}"]`);
      toast(`Ενεργοποιήθηκε ο τύπος «${typeLabel(nextType)}». Τα αντίστοιχα πεδία εμφανίστηκαν παρακάτω.`);
    });
    root.querySelectorAll('[data-true-false-correct]').forEach(select => select.onchange = event => {
      event.preventDefault();
      event.stopPropagation();
      const index = number(select.dataset.index);
      const question = quiz.questions[index];
      if (!question || question.type !== 'true_false') return;
      const correctIndex = number(select.value);
      question.answers.forEach((answer, answerIndex) => {
        answer.is_correct = answerIndex === correctIndex;
        answer.score = answer.is_correct ? Math.max(1, number(question.settings.points, 1)) : 0;
      });
      markDirty();
      refreshQuestionCard(index, `[data-true-false-correct][data-index="${index}"]`);
    });
    root.querySelectorAll('[data-answer-question]').forEach(input => input.oninput = event => { quiz.questions[number(input.dataset.answerQuestion)].answers[number(input.dataset.answer)].content.text = event.target.value; markDirty(); });
    root.querySelectorAll('[data-answer-match]').forEach(input => input.oninput = event => { quiz.questions[number(input.dataset.question)].answers[number(input.dataset.answer)].content.match_text = event.target.value; markDirty(); });
    root.querySelectorAll('[data-personality-weight]').forEach(input => input.oninput = event => { const answer = quiz.questions[number(input.dataset.question)].answers[number(input.dataset.answer)]; answer.content.personality_weights = answer.content.personality_weights || {}; answer.content.personality_weights[input.dataset.profile] = number(event.target.value); markDirty(); });
    root.querySelectorAll('[data-score-question]').forEach(input => input.oninput = event => { quiz.questions[number(input.dataset.scoreQuestion)].answers[number(input.dataset.scoreAnswer)].score = number(event.target.value); markDirty(); });
    root.querySelectorAll('[data-correct-question]').forEach(input => input.onchange = () => {
      const question = quiz.questions[number(input.dataset.correctQuestion)]; const index = number(input.dataset.correctAnswer);
      if (question.type === 'multiple_answers' || question.type === 'open_text') {
        question.answers[index].is_correct = input.checked;
        if (input.checked && number(question.answers[index].score) <= 0) question.answers[index].score = 1;
      } else {
        question.answers.forEach((answer, answerIndex) => { answer.is_correct = answerIndex === index; if (answer.is_correct && number(answer.score) === 0) answer.score = 1; });
      }
      markDirty();
    });
    root.querySelectorAll('[data-add-answer]').forEach(button => button.onclick = event => {
      event.preventDefault(); event.stopPropagation();
      const questionIndex=number(button.dataset.addAnswer); const question=quiz.questions[questionIndex];
      question.answers.push(newAnswer('', false, 0)); markDirty();
      const answerIndex=question.answers.length-1;
      preserveBuilderPosition(renderBuilder, `[data-answer-question="${questionIndex}"][data-answer="${answerIndex}"]`);
    });
    root.querySelectorAll('[data-delete-answer]').forEach(button => button.onclick = event => { event.preventDefault(); const questionIndex=number(button.dataset.deleteAnswer); const question=quiz.questions[questionIndex]; if(question.answers.length<=1){toast('Η ερώτηση πρέπει να έχει τουλάχιστον μία απάντηση.');return;} question.answers.splice(number(button.dataset.answer),1); normaliseQuestionCorrectness(question); markDirty(); preserveBuilderPosition(renderBuilder); });
    root.querySelectorAll('[data-move-answer]').forEach(button => button.onclick = () => { const answers = quiz.questions[number(button.dataset.moveAnswer)].answers; const index = number(button.dataset.answer); const target = index + number(button.dataset.direction); if (target < 0 || target >= answers.length) return; [answers[index], answers[target]] = [answers[target], answers[index]]; markDirty(); renderBuilder(); });
    root.querySelectorAll('[data-delete-question]').forEach(button => button.onclick = () => { if (confirm('Να διαγραφεί αυτή η ερώτηση;')) { const index=number(button.dataset.deleteQuestion); quiz.questions.splice(index, 1); state.openQuestionSettings = null; state.activeQuestionKey = String(quiz.questions[Math.min(index,quiz.questions.length-1)]?.settings?.key || ''); markDirty(); renderBuilder(); } });
    root.querySelectorAll('[data-duplicate-question]').forEach(button => button.onclick = () => { const index = number(button.dataset.duplicateQuestion); const copy=cloneQuestionTemplate(quiz.questions[index]); quiz.questions.splice(index + 1, 0, copy); state.activeQuestionKey=String(copy.settings.key||''); markDirty(); renderBuilder(); });
    root.querySelectorAll('[data-save-bank]').forEach(button => button.onclick = async () => {
      const question = quiz.questions[number(button.dataset.saveBank)];
      const title = window.prompt('Τίτλος στη βιβλιοθήκη ερωτήσεων:', question.content.title || 'Επαναχρησιμοποιήσιμη ερώτηση');
      if (title === null) return;
      try {
        state.questionBank = await api('question-bank', {method: 'POST', body: JSON.stringify({title, question})});
        toast('Η ερώτηση αποθηκεύτηκε στη βιβλιοθήκη');
        renderBuilder();
      } catch (error) { alert(error.message); }
    });
    root.querySelectorAll('[data-move-question]').forEach(button => button.onclick = () => { const index = number(button.dataset.moveQuestion); const target = index + number(button.dataset.direction); if (target < 0 || target >= quiz.questions.length) return; [quiz.questions[index], quiz.questions[target]] = [quiz.questions[target], quiz.questions[index]]; markDirty(); renderBuilder(); });
    root.querySelectorAll('[data-condition-enabled]').forEach(input => input.onchange = () => {
      const index = number(input.dataset.index);
      const question = quiz.questions[index];
      state.openQuestionSettings = index;
      question.settings.condition.enabled = input.checked;
      if (!Array.isArray(question.settings.condition.rules) || !question.settings.condition.rules.length) {
        const previous = quiz.questions.slice(0, index).filter(item => item.type !== 'open_text');
        if (previous[0]) question.settings.condition.rules = [{operator: 'equals', question_key: previous[0].settings.key, answer_key: previous[0].answers?.[0]?.content?.key || ''}];
      }
      markDirty(); renderBuilder();
    });
    root.querySelectorAll('[data-condition-match]').forEach(select => select.onchange = () => {
      quiz.questions[number(select.dataset.index)].settings.condition.match = select.value;
      markDirty();
    });
    root.querySelectorAll('[data-condition-rule-question]').forEach(select => select.onchange = () => {
      const index = number(select.dataset.index);
      state.openQuestionSettings = index;
      const question = quiz.questions[index];
      const rule = question.settings.condition.rules[number(select.dataset.rule)];
      const source = quiz.questions.find(item => item.settings.key === select.value);
      rule.question_key = select.value;
      rule.answer_key = source?.answers?.[0]?.content?.key || '';
      markDirty(); renderBuilder();
    });
    root.querySelectorAll('[data-condition-rule-operator]').forEach(select => select.onchange = () => {
      const index = number(select.dataset.index);
      state.openQuestionSettings = index;
      const question = quiz.questions[index];
      question.settings.condition.rules[number(select.dataset.rule)].operator = select.value;
      markDirty(); renderBuilder();
    });
    root.querySelectorAll('[data-condition-rule-answer]').forEach(select => select.onchange = () => {
      quiz.questions[number(select.dataset.index)].settings.condition.rules[number(select.dataset.rule)].answer_key = select.value;
      markDirty();
    });
    root.querySelectorAll('[data-condition-add-rule]').forEach(button => button.onclick = () => {
      const index = number(button.dataset.index); state.openQuestionSettings = index; const question = quiz.questions[index]; const previous = quiz.questions.slice(0, index).filter(item => item.type !== 'open_text');
      const source = previous[0]; if (!source) return;
      question.settings.condition.rules = Array.isArray(question.settings.condition.rules) ? question.settings.condition.rules : [];
      question.settings.condition.rules.push({operator: 'equals', question_key: source.settings.key, answer_key: source.answers?.[0]?.content?.key || ''});
      markDirty(); renderBuilder();
    });
    root.querySelectorAll('[data-condition-delete-rule]').forEach(button => button.onclick = () => {
      const index = number(button.dataset.index); state.openQuestionSettings = index;
      const rules = quiz.questions[index].settings.condition.rules;
      if (rules.length <= 1) return;
      rules.splice(number(button.dataset.rule), 1); markDirty(); renderBuilder();
    });
    root.querySelectorAll('[data-question-setting]').forEach(input => {
      const handler = event => {
        const question = quiz.questions[number(input.dataset.index)];
        const key = input.dataset.questionSetting;
        question.settings[key] = input.type === 'checkbox' ? input.checked : input.type === 'number' ? number(event.target.value) : event.target.value;
        if (key === 'points' && singleCorrectTypes.includes(question.type)) {
          question.answers.forEach(answer => { answer.score = bool(answer.is_correct) ? Math.max(0, number(question.settings.points, 1)) : 0; });
        }
        markDirty();
        refreshPreview();
      };
      const eventName = input.type === 'checkbox' || input.tagName === 'SELECT' ? 'onchange' : 'oninput';
      input[eventName] = handler;
    });
  };

  const bindBankEvents = () => {
    root.querySelectorAll('[data-bank-insert]').forEach(button => button.onclick = () => {
      const item = state.questionBank.find(entry => number(entry.id) === number(button.dataset.bankInsert));
      if (!item?.question) return;
      state.quiz.questions.push(cloneQuestionTemplate(item.question));
      state.tab = 'questions';
      markDirty();
      renderBuilder();
      toast('Η ερώτηση προστέθηκε');
    });
    root.querySelectorAll('[data-bank-delete]').forEach(button => button.onclick = async () => {
      if (!confirm('Να διαγραφεί αυτή η ερώτηση από την τράπεζα;')) return;
      try {
        await api(`question-bank/${button.dataset.bankDelete}`, {method: 'DELETE'});
        state.questionBank = state.questionBank.filter(item => number(item.id) !== number(button.dataset.bankDelete));
        toast('Η ερώτηση διαγράφηκε από την τράπεζα');
        renderBuilder();
      } catch (error) { alert(error.message); }
    });
  };

  const bindCategoryEvents = () => {
    const form = root.querySelector('[data-category-form]');
    const nameInput = root.querySelector('[data-category-name]');
    const slugInput = root.querySelector('[data-category-slug]');
    const descriptionInput = root.querySelector('[data-category-description]');
    const colorInput = root.querySelector('[data-category-color]');
    const colorTextInput = root.querySelector('[data-category-color-text]');
    const iconInput = root.querySelector('[data-category-icon]');
    const title = root.querySelector('[data-category-form-title]');
    const kicker = root.querySelector('[data-category-form-kicker]');
    let editingId = number(form?.dataset.editingId);

    const preview = () => {
      const element = root.querySelector('[data-category-preview]');
      if (!element) return;
      const color = /^#[0-9a-f]{6}$/i.test(colorInput?.value || '') ? colorInput.value : '#d9bd85';
      const icon = iconInput?.value || 'folder';
      element.style.setProperty('--category-color', color);
      element.querySelector('span').textContent = categoryIcons[icon] || categoryIcons.folder;
      element.querySelector('b').textContent = nameInput?.value.trim() || 'Νέα κατηγορία';
      element.querySelector('small').textContent = slugInput?.value.trim() || 'category-slug';
    };
    const clearForm = () => {
      editingId = 0;
      if (form) form.dataset.editingId = '0';
      if (nameInput) nameInput.value = '';
      if (slugInput) slugInput.value = '';
      if (descriptionInput) descriptionInput.value = '';
      if (colorInput) colorInput.value = '#d9bd85';
      if (colorTextInput) colorTextInput.value = '#d9bd85';
      if (iconInput) iconInput.value = 'folder';
      if (title) title.textContent = 'Δημιουργία κατηγορίας';
      if (kicker) kicker.textContent = 'ΝΕΑ ΚΑΤΗΓΟΡΙΑ';
      preview();
    };
    const openForm = category => {
      editingId = number(category?.id);
      if (form) form.dataset.editingId = String(editingId);
      if (nameInput) nameInput.value = category?.name || '';
      if (slugInput) slugInput.value = category?.slug || '';
      if (descriptionInput) descriptionInput.value = category?.description || '';
      if (colorInput) colorInput.value = category?.color || '#d9bd85';
      if (colorTextInput) colorTextInput.value = category?.color || '#d9bd85';
      if (iconInput) iconInput.value = category?.icon || 'folder';
      if (title) title.textContent = editingId ? 'Επεξεργασία κατηγορίας' : 'Δημιουργία κατηγορίας';
      if (kicker) kicker.textContent = editingId ? 'ΕΠΕΞΕΡΓΑΣΙΑ' : 'ΝΕΑ ΚΑΤΗΓΟΡΙΑ';
      preview();
      nameInput?.focus();
      form?.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    };
    const rerender = () => state.view === 'categories' ? renderCategoriesPage() : renderBuilder();

    root.querySelector('[data-category-new]')?.addEventListener('click', () => openForm(null));
    root.querySelector('[data-category-cancel]')?.addEventListener('click', clearForm);
    root.querySelectorAll('[data-category-edit]').forEach(button => button.onclick = () => openForm(state.categories.find(category => number(category.id) === number(button.dataset.categoryEdit))));
    root.querySelector('[data-category-search]')?.addEventListener('input', event => {
      state.categoryQuery = event.target.value;
      const query = state.categoryQuery.trim().toLocaleLowerCase('el');
      root.querySelectorAll('[data-category-item]').forEach(item => {
        const category = state.categories.find(entry => number(entry.id) === number(item.dataset.categoryItem));
        const haystack = `${category?.name || ''} ${category?.slug || ''} ${category?.description || ''}`.toLocaleLowerCase('el');
        item.hidden = Boolean(query && !haystack.includes(query));
      });
    });
    [nameInput, slugInput, descriptionInput].forEach(input => input?.addEventListener('input', preview));
    colorInput?.addEventListener('input', () => { if (colorTextInput) colorTextInput.value = colorInput.value; preview(); });
    colorTextInput?.addEventListener('change', () => { if (/^#[0-9a-f]{6}$/i.test(colorTextInput.value) && colorInput) colorInput.value = colorTextInput.value; preview(); });
    iconInput?.addEventListener('change', preview);
    root.querySelector('[data-category-save]')?.addEventListener('click', async () => {
      const name = nameInput?.value.trim() || '';
      if (!name) { alert('Συμπληρώστε όνομα κατηγορίας.'); nameInput?.focus(); return; }
      const button = root.querySelector('[data-category-save]');
      button.disabled = true;
      try {
        const saved = await api(`categories${editingId ? '/' + editingId : ''}`, {
          method: editingId ? 'PUT' : 'POST',
          body: JSON.stringify({
            name, slug: slugInput?.value || '', description: descriptionInput?.value || '',
            color: colorInput?.value || '#d9bd85', icon: iconInput?.value || 'folder'
          })
        });
        const index = state.categories.findIndex(category => number(category.id) === number(saved.id));
        if (index >= 0) state.categories[index] = saved; else state.categories.push(saved);
        state.categories.sort((a, b) => String(a.name).localeCompare(String(b.name), 'el'));
        toast(editingId ? 'Η κατηγορία ενημερώθηκε' : 'Η κατηγορία δημιουργήθηκε');
        rerender();
      } catch (error) { button.disabled = false; alert(error.message); }
    });
    root.querySelectorAll('[data-category-delete]').forEach(button => button.onclick = async () => {
      const id = number(button.dataset.categoryDelete);
      if (!confirm('Να διαγραφεί η κατηγορία; Τα quiz θα παραμείνουν χωρίς κατηγορία.')) return;
      try {
        await api(`categories/${id}`, {method: 'DELETE'});
        state.categories = state.categories.filter(category => number(category.id) !== id);
        if (state.quiz && number(state.quiz.category_id) === id) { state.quiz.category_id = 0; state.quiz.category = null; markDirty(); }
        toast('Η κατηγορία διαγράφηκε');
        rerender();
      } catch (error) { alert(error.message); }
    });
    preview();
  };

  const bindSettingsEvents = () => {
    const quiz = state.quiz;
    root.querySelectorAll('[data-setting]').forEach(input => {
      const eventName = input.type === 'checkbox' || input.tagName === 'SELECT' ? 'change' : 'input';
      input.addEventListener(eventName, event => {
        const key = input.dataset.setting;
        if (key === 'slug') quiz.slug = event.target.value;
        if (key === 'quiz_type') quiz.quiz_type = event.target.value;
        if (key === 'visibility_scope') quiz.visibility_scope = event.target.value;
        if (key === 'category_id') {
          quiz.category_id = number(event.target.value);
          const category = state.categories.find(item => number(item.id) === quiz.category_id);
          quiz.category = category ? {id: category.id, name: category.name, slug: category.slug, color: category.color, icon: category.icon} : null;
          refreshPreview();
        }
        if (key === 'show_progress') quiz.settings.show_progress = input.checked;
        if (key === 'random_questions') quiz.settings.random_questions = input.checked;
        if (key === 'random_question_limit') quiz.settings.random_question_limit = number(event.target.value);
        if (key === 'review_answers') quiz.settings.review_answers = input.checked;
        if (key === 'show_pass_fail') quiz.settings.show_pass_fail = input.checked;
        if (key === 'pass_score') quiz.settings.pass_score = number(event.target.value);
        if (key === 'embed_mode') quiz.settings.embed_mode = event.target.value;
        if (key === 'embed_domains') quiz.settings.embed_domains = event.target.value.split(/[\r\n,;]+/).map(value => value.trim()).filter(Boolean);
        if (key === 'embed_block_message') quiz.settings.embed_block_message = event.target.value;
        if (key === 'show_feedback') quiz.settings.show_feedback = input.checked;
        if (key === 'show_correct_answer') quiz.settings.show_correct_answer = input.checked;
        if (key === 'allow_restart') quiz.settings.allow_restart = input.checked;
        if (key === 'status') {
          quiz.status = event.target.value;
          if (quiz.status !== 'scheduled') quiz.scheduled_at = null;
          renderBuilder();
        }
        if (key === 'scheduled_at') quiz.scheduled_at = event.target.value ? new Date(event.target.value).toISOString() : null;
        if (key === 'expires_at') quiz.expires_at = event.target.value ? new Date(event.target.value).toISOString() : null;
        markDirty();
      });
    });
  };

  const contrastRatio = (foreground, background) => {
    const luminance = hex => {
      const clean = String(hex || '').replace('#', '');
      if (!/^[0-9a-f]{6}$/i.test(clean)) return 0;
      const values = [0, 2, 4].map(index => parseInt(clean.slice(index, index + 2), 16) / 255)
        .map(value => value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4));
      return 0.2126 * values[0] + 0.7152 * values[1] + 0.0722 * values[2];
    };
    const first = luminance(foreground);
    const second = luminance(background);
    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
  };

  const updateContrastReport = () => {
    const report = root.querySelector('[data-contrast-report]');
    if (!report || !state.quiz) return;
    const checks = [
      ['Κείμενο / κάρτα', state.quiz.theme.text, state.quiz.theme.background],
      ['Δευτερεύον / κάρτα', state.quiz.theme.muted, state.quiz.theme.background],
      ['Κείμενο / κουμπί', state.quiz.theme.button_text, state.quiz.theme.button],
      ['Κείμενο / απάντηση', state.quiz.theme.text, state.quiz.theme.answer]
    ].map(([label, foreground, background]) => {
      const ratio = contrastRatio(foreground, background);
      return {label, ratio, ok: ratio >= 4.5};
    });
    report.innerHTML = `<strong>Έλεγχος αντίθεσης WCAG AA</strong>${checks.map(check => `<span class="${check.ok ? 'is-good' : 'is-warning'}">${esc(check.label)}: ${check.ratio.toFixed(2)}:1 ${check.ok ? '✓' : '⚠'}</span>`).join('')}`;
  };

  const bestTextColor = background => contrastRatio('#ffffff', background) >= contrastRatio('#111111', background) ? '#ffffff' : '#111111';

  const bindThemeEvents = () => {
    const quiz = state.quiz;
    root.querySelectorAll('[data-theme]').forEach(input => {
      const handler = event => {
        const key = input.dataset.theme;
        quiz.theme[key] = key === 'radius' ? number(event.target.value) : event.target.value;
        if (key !== 'font' && key !== 'shadow' && key !== 'radius') quiz.theme.preset = 'custom';
        const sibling = input.parentElement?.querySelector('span');
        if (key === 'radius' && sibling) sibling.textContent = `${quiz.theme.radius}px`;
        const textInput = root.querySelector(`[data-theme-text="${key}"]`);
        if (textInput) textInput.value = quiz.theme[key];
        markDirty(); refreshPreview(); updateContrastReport();
      };
      input.addEventListener(input.type === 'range' || input.type === 'color' ? 'input' : 'change', handler);
    });
    root.querySelectorAll('[data-theme-text]').forEach(input => input.onchange = event => {
      const key = input.dataset.themeText;
      if (/^#[0-9a-f]{6}$/i.test(event.target.value)) {
        quiz.theme[key] = event.target.value;
        quiz.theme.preset = 'custom';
        const color = root.querySelector(`[data-theme="${key}"]`);
        if (color) color.value = event.target.value;
        markDirty(); refreshPreview(); updateContrastReport();
      }
    });
    root.querySelector('[data-theme-preset-select]')?.addEventListener('change', event => {
      const preset = quizThemePresets[event.target.value] || quizThemePresets.atelier;
      quiz.theme = {...quiz.theme, ...clone(preset)};
      delete quiz.theme.label;
      delete quiz.theme.description;
      markDirty(); renderBuilder();
    });
    root.querySelector('[data-theme-auto-contrast]')?.addEventListener('click', () => {
      quiz.theme.text = bestTextColor(quiz.theme.background);
      quiz.theme.button_text = bestTextColor(quiz.theme.button);
      quiz.theme.muted = quiz.theme.text === '#ffffff' ? '#d4d4d8' : '#374151';
      if (contrastRatio(quiz.theme.text, quiz.theme.answer) < 4.5) quiz.theme.answer = quiz.theme.background;
      quiz.theme.preset = 'custom';
      markDirty(); renderBuilder();
    });
    updateContrastReport();
  };

  const bindResultEvents = () => {
    const ranges = state.quiz.settings.results;
    const profiles = state.quiz.settings.personality_profiles;
    root.querySelector('[data-add-result]')?.addEventListener('click', () => { const last = ranges[ranges.length - 1]; ranges.push({min: last ? number(last.max) + 1 : 0, max: last ? number(last.max) + 5 : 5, title: 'Νέο αποτέλεσμα', description: '', image_id: 0, image_url: '', cta_label: '', cta_url: ''}); markDirty(); renderBuilder(); });
    root.querySelectorAll('[data-result]').forEach(input => input.oninput = event => { const range = ranges[number(input.dataset.index)]; range[input.dataset.result] = input.type === 'number' ? number(event.target.value) : event.target.value; markDirty(); });
    root.querySelectorAll('[data-delete-result]').forEach(button => button.onclick = () => { ranges.splice(number(button.dataset.deleteResult), 1); markDirty(); renderBuilder(); });
    root.querySelector('[data-add-profile]')?.addEventListener('click', () => { profiles.push({key: makeKey('profile'), title: `Προφίλ ${profiles.length + 1}`, description: '', image_id: 0, image_url: '', cta_label: '', cta_url: ''}); markDirty(); renderBuilder(); });
    root.querySelectorAll('[data-profile]').forEach(input => input.oninput = event => { const profile = profiles[number(input.dataset.index)]; const oldKey = profile.key; profile[input.dataset.profile] = event.target.value; if (input.dataset.profile === 'key') { const clean = String(event.target.value).toLowerCase().replace(/[^a-z0-9_-]+/g, '_'); profile.key = clean; state.quiz.questions.forEach(question => question.answers.forEach(answer => { const weights = answer.content.personality_weights || {}; if (oldKey && oldKey !== clean && Object.prototype.hasOwnProperty.call(weights, oldKey)) { weights[clean] = weights[oldKey]; delete weights[oldKey]; } })); } markDirty(); });
    root.querySelectorAll('[data-delete-profile]').forEach(button => button.onclick = () => { const index = number(button.dataset.deleteProfile); const key = profiles[index]?.key; profiles.splice(index, 1); if (key) state.quiz.questions.forEach(question => question.answers.forEach(answer => { if (answer.content.personality_weights) delete answer.content.personality_weights[key]; })); markDirty(); renderBuilder(); });
    root.querySelectorAll('[data-personality-setting]').forEach(select => select.onchange = () => { state.quiz.settings[select.dataset.personalitySetting] = select.value; markDirty(); });
  };

  const bindMediaEvents = () => {
    root.querySelectorAll('[data-media]').forEach(button => button.onclick = () => chooseMedia(attachment => {
      const target = button.dataset.media; const index = number(button.dataset.index);
      if (target === 'intro') { state.quiz.settings.intro.image_id = attachment.id; state.quiz.settings.intro.image_url = attachment.url; }
      if (target === 'question') { state.quiz.questions[index].content.image_id = attachment.id; state.quiz.questions[index].content.image_url = attachment.url; }
      if (target === 'answer') { const questionIndex = number(button.dataset.question); const answerIndex = number(button.dataset.answer); state.quiz.questions[questionIndex].answers[answerIndex].content.image_id = attachment.id; state.quiz.questions[questionIndex].answers[answerIndex].content.image_url = attachment.url; }
      if (target === 'result') { state.quiz.settings.results[index].image_id = attachment.id; state.quiz.settings.results[index].image_url = attachment.url; }
      if (target === 'profile') { state.quiz.settings.personality_profiles[index].image_id = attachment.id; state.quiz.settings.personality_profiles[index].image_url = attachment.url; }
      markDirty(); renderBuilder();
    }));
    root.querySelectorAll('[data-remove-media]').forEach(button => button.onclick = () => {
      const target = button.dataset.removeMedia; const index = number(button.dataset.index);
      if (target === 'intro') { state.quiz.settings.intro.image_id = 0; state.quiz.settings.intro.image_url = ''; }
      if (target === 'question') { state.quiz.questions[index].content.image_id = 0; state.quiz.questions[index].content.image_url = ''; }
      if (target === 'answer') { const questionIndex = number(button.dataset.question); const answerIndex = number(button.dataset.answer); state.quiz.questions[questionIndex].answers[answerIndex].content.image_id = 0; state.quiz.questions[questionIndex].answers[answerIndex].content.image_url = ''; }
      if (target === 'result') { state.quiz.settings.results[index].image_id = 0; state.quiz.settings.results[index].image_url = ''; }
      if (target === 'profile') { state.quiz.settings.personality_profiles[index].image_id = 0; state.quiz.settings.personality_profiles[index].image_url = ''; }
      markDirty(); renderBuilder();
    });
  };

  const chooseMedia = callback => {
    if (!window.wp?.media) { alert('Η βιβλιοθήκη πολυμέσων του WordPress δεν είναι διαθέσιμη σε αυτή τη σελίδα.'); return; }
    const frame = wp.media({title: 'Επιλογή εικόνας', button: {text: 'Χρήση εικόνας'}, multiple: false, library: {type: 'image'}});
    frame.on('select', () => callback(frame.state().get('selection').first().toJSON()));
    frame.open();
  };

  const refreshPreview = () => {
    root.querySelectorAll('[data-preview-surface]').forEach(surface => { surface.innerHTML = previewMarkup(); });
    ensureButtonTypes();
    bindPreviewEvents();
  };

  const applyServerIdentity = (current, saved) => {
    current.id = saved.id;
    current.slug = saved.slug;
    current.quiz_type = saved.quiz_type;
    current.created_at = saved.created_at;
    current.updated_at = saved.updated_at;
    current.author_id = saved.author_id;
    current.category_id = saved.category_id;
    current.category = saved.category;
    current.status = saved.status;
    current.scheduled_at = saved.scheduled_at;
    current.expires_at = saved.expires_at;
    current.questions.forEach((question, index) => {
      const savedQuestion = saved.questions[index];
      if (!savedQuestion) return;
      question.id = savedQuestion.id;
      question.quiz_id = savedQuestion.quiz_id;
      question.answers.forEach((answer, answerIndex) => {
        const savedAnswer = savedQuestion.answers[answerIndex];
        if (!savedAnswer) return;
        answer.id = savedAnswer.id;
        answer.question_id = savedAnswer.question_id;
      });
    });
  };

  const save = async (forcedStatus = null, autosave = false) => {
    if (state.saving) return;
    const publishing = forcedStatus === 'published';
    const issues = collectValidationIssues(state.quiz, publishing);
    if (issues.length) {
      if (!autosave) {
        state.validationIssues = issues;
        state.tab = 'questions';
        const first = issues[0];
        if (first.questionIndex !== null && state.quiz.questions[first.questionIndex]) state.activeQuestionKey = String(state.quiz.questions[first.questionIndex].settings.key || '');
        renderBuilder();
        requestAnimationFrame(() => root.querySelector('.wpqs-validation-summary')?.scrollIntoView({behavior:'smooth',block:'start'}));
        toast(`Δεν μπορεί να ${publishing ? 'δημοσιευτεί' : 'αποθηκευτεί'} ακόμη. Διορθώστε τα επισημασμένα σημεία.`);
      }
      return;
    }
    state.validationIssues = [];
    state.saving = true;
    const originalStatus = state.quiz.status;
    if (forcedStatus) state.quiz.status = forcedStatus;
    normaliseQuizCorrectness(state.quiz);
    const submittedQuiz = clone(state.quiz);
    const recoveryBeforeSave = recoveryKey(submittedQuiz);
    const requestPath = `quizzes${submittedQuiz.id ? '/' + submittedQuiz.id : ''}`;
    try {
      const payload = clone(submittedQuiz); payload._autosave = autosave; if (submittedQuiz.id && submittedQuiz.updated_at) payload._expected_updated_at = submittedQuiz.updated_at;
      const savedQuiz = normaliseQuiz(await api(requestPath, {method: submittedQuiz.id ? 'PUT' : 'POST', body: JSON.stringify(payload)}));
      const changedDuringRequest = JSON.stringify(state.quiz) !== JSON.stringify(submittedQuiz);
      state.conflict = null; state.autosaveFailures = 0;
      /*
       * Autosave must never replace state.quiz while the current DOM remains mounted.
       * Event handlers close over the original quiz object. Replacing that object made
       * question-type changes update a stale reference, so the toast confirmed the
       * change while the card immediately rendered the old type. Keep object identity
       * during autosave and merge only server-owned IDs/timestamps instead.
       */
      if (autosave) {
        applyServerIdentity(state.quiz, savedQuiz);
        state.dirty = changedDuringRequest;
      } else if (changedDuringRequest) {
        applyServerIdentity(state.quiz, savedQuiz);
        state.dirty = true;
      } else {
        state.quiz = savedQuiz;
        state.dirty = false;
      }
      try { localStorage.removeItem(recoveryBeforeSave); removeRecovery(state.quiz); } catch (_) {}
      if (autosave) {
        const label = root.querySelector('.saved');
        if (label) label.textContent = state.dirty ? '● Μη αποθηκευμένες αλλαγές' : '● Αυτόματη αποθήκευση';
      } else {
        toast(forcedStatus === 'published' ? 'Το quiz δημοσιεύτηκε' : 'Οι αλλαγές αποθηκεύτηκαν');
        renderBuilder();
      }
    } catch (error) {
      state.quiz.status = originalStatus;
      if (error.status === 409) {
        state.conflict = {latest: error.data?.data?.latest || error.data?.latest || null, message: error.message};
        state.dirty = true;
        storeRecovery();
        renderBuilder();
        toast('Η αποθήκευση σταμάτησε για να μη χαθούν αλλαγές άλλου χρήστη.');
      } else {
        state.autosaveFailures += 1;
        if (!autosave) toast(error.message);
        else if (state.autosaveFailures === 1) toast('Η αυτόματη αποθήκευση απέτυχε. Θα ξαναγίνει όταν υπάρχει σύνδεση.');
      }
    } finally {
      state.saving = false;
    }
  };

  const analyticsQuery = () => {
    const params = new URLSearchParams();
    if (state.analyticsPreset === 'custom') {
      if (state.analyticsFrom) params.set('from', state.analyticsFrom);
      if (state.analyticsTo) params.set('to', state.analyticsTo);
    } else {
      const days = Math.max(1, number(state.analyticsPreset, 30));
      const to = new Date();
      const from = new Date(to.getTime() - (days - 1) * 86400000);
      params.set('from', from.toISOString().slice(0, 10));
      params.set('to', to.toISOString().slice(0, 10));
    }
    params.set('group', state.analyticsGroup || 'day');
    return params.toString();
  };

  const emptyAnalytics = () => ({overview: {views: 0, starts: 0, completions: 0, completion_rate: 0, average_score: 0, average_time: 0, abandoned: 0}, comparison: {}, timeseries: [], funnel: [], questions: [], audience: {}, result_distribution: [], score_distribution: [], pass_distribution: [], latest_completions: [], quiz_breakdown: [], data_notes: {}});

  const loadAnalytics = async () => {
    if (!state.quiz?.id || !WPQS.canAnalytics) return;
    state.loadingPanel = true; renderBuilder();
    try { state.analytics = await api(`quizzes/${state.quiz.id}/analytics?${analyticsQuery()}`); }
    catch (error) { state.analytics = emptyAnalytics(); alert(error.message); }
    finally { state.loadingPanel = false; if (state.tab === 'analytics') renderBuilder(); }
  };

  const loadGlobalAnalytics = async () => {
    if (!WPQS.canAnalytics) return;
    state.loadingPanel = true;
    renderGlobalAnalytics();
    try { state.dashboardAnalytics = await api(`analytics?${analyticsQuery()}`); }
    catch (error) { state.dashboardAnalytics = emptyAnalytics(); alert(error.message); }
    finally { state.loadingPanel = false; if (state.view === 'analytics-global') renderGlobalAnalytics(); }
  };

  const loadRevisions = async () => {
    if (!state.quiz?.id) return;
    state.loadingPanel = true; renderBuilder();
    try { state.revisions = await api(`quizzes/${state.quiz.id}/revisions`); }
    catch (error) { state.revisions = []; alert(error.message); }
    finally { state.loadingPanel = false; if (state.tab === 'history') renderBuilder(); }
  };

  window.addEventListener('beforeunload', event => { if (state.view === 'builder' && state.dirty) { storeRecovery(); event.preventDefault(); event.returnValue = ''; } });
  document.addEventListener('keydown', event => {
    if (state.view !== 'builder') return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); save(null, false); }
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); openBuilderPreviewModal(); }
    if (event.altKey && event.key.toLowerCase() === 'n' && state.tab === 'questions') { event.preventDefault(); const question=newQuestion(); state.quiz.questions.push(question); state.activeQuestionKey=String(question.settings.key||''); markDirty(); renderBuilder(); }
  });

  window.addEventListener('offline', () => {
    state.online = false;
    if (state.view === 'builder') { storeRecovery(); renderBuilder(); }
    toast('Δεν υπάρχει σύνδεση. Οι αλλαγές κρατούνται τοπικά.');
  });
  window.addEventListener('online', () => {
    state.online = true;
    toast('Η σύνδεση επανήλθε.');
    if (state.view === 'builder' && state.dirty && !state.conflict) {
      window.clearTimeout(state.autosaveTimer);
      state.autosaveTimer = window.setTimeout(() => save(null, true), 700);
      renderBuilder();
    }
  });

  load();
})();

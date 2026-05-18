const App = (() => {
            // DOM Elements
            const els = {
                input: document.getElementById('input-text'),
                outputContainer: document.getElementById('output-container'),
                convertBtn: document.getElementById('convert-btn'),
                clearBtn: document.getElementById('clear-btn'),
                copyBtn: document.getElementById('copy-btn'),
                tabs: document.querySelectorAll('.tab'),
                providerSelect: document.getElementById('provider'),
                apiKeyInput: document.getElementById('api-key'),
                apiKeyLabel: document.getElementById('api-key-label'),
                modelInput: document.getElementById('model-input'),
                loadingOverlay: document.getElementById('loading-overlay'),
                storageMode: document.getElementById('storage-mode'),
                presetSelect: document.getElementById('preset-select'),
                applyPresetBtn: document.getElementById('apply-preset-btn'),
                downloadJsonBtn: document.getElementById('download-json-btn'),
                downloadCsvBtn: document.getElementById('download-csv-btn'),
                refreshModelsBtn: document.getElementById('refresh-models-btn'),
                modelSourceHelp: document.getElementById('model-source-help')
            };

            const PROVIDER_CONFIG = {
                gemini: { label: 'Gemini API Key', keyPlaceholder: 'Google AI Studio API 키', defaultModel: 'gemini-2.0-flash' },
                openai: { label: 'OpenAI API Key', keyPlaceholder: 'OpenAI API 키 (sk-...)', defaultModel: 'gpt-4o-mini' },
                anthropic: { label: 'Anthropic API Key', keyPlaceholder: 'Anthropic API 키 (sk-ant-...)', defaultModel: 'claude-3-5-sonnet-latest' }
            };

            const PROVIDER_MODELS = {
                gemini: ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'],
                openai: ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'],
                anthropic: ['claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest', 'claude-3-opus-latest']
            };


            const isCompatibleTextModel = (provider, modelId) => {
                const id = String(modelId || '').toLowerCase();
                if (!id) return false;

                if (provider === 'gemini') {
                    // 텍스트 파싱 용도 기준: gemini 계열 중 flash/pro 허용, 영상/이미지/임베딩 계열 제외
                    if (!id.includes('gemini')) return false;
                    if (id.includes('veo') || id.includes('imagen') || id.includes('embedding') || id.includes('aqa')) return false;
                    if (id.includes('nano') || id.includes('banana')) return false;
                    return id.includes('flash') || id.includes('pro');
                }

                if (provider === 'openai') {
                    return id.startsWith('gpt-');
                }

                if (provider === 'anthropic') {
                    return id.startsWith('claude-');
                }

                return false;
            };

            let state = {
                parsedData: null,
                currentView: 'json',
                provider: 'gemini',
                apiKey: '',
                model: '',
                isLoading: false,
                chartInstance: null,
                storageMode: 'local'
            };

            const MODEL_CACHE_KEY = 'gemini_latest_flash_model';
            const MODEL_CACHE_AT_KEY = 'gemini_latest_flash_model_checked_at';
            const MODEL_CACHE_TTL_MS = 24 * 60 * 60 * 1000;
            const providerKeyStorageKey = (provider) => `api_key_${provider}`;
            const providerModelStorageKey = (provider) => `model_${provider}`;

            const readStorage = (key) => {
                if (state.storageMode === 'session') return sessionStorage.getItem(key);
                if (state.storageMode === 'none') return null;
                return localStorage.getItem(key);
            };

            const writeStorage = (key, value) => {
                if (state.storageMode === 'none') return;
                if (state.storageMode === 'session') {
                    sessionStorage.setItem(key, value);
                    localStorage.removeItem(key);
                } else {
                    localStorage.setItem(key, value);
                    sessionStorage.removeItem(key);
                }
            };

            const clearStorageForKey = (key) => {
                localStorage.removeItem(key);
                sessionStorage.removeItem(key);
            };

            const SAMPLE_INPUTS = {
                single: '10/23 09:00 - 12:00 오전 근무',
                multi: '10/23 09:00 - 12:00 오전 근무\n10/23 13:00 - 18:00 오후 근무',
                memo: '10월 24일 오후 2시부터 6시까지 회의\n10/24 19:00~20:30 문서작업'
            };

            const init = () => {
                loadState();
                bindEvents();
                applyProviderUI();
                    setModelSourceHelp('* 모델 목록은 기본 내장값입니다. API Key 입력 후 "모델 불러오기"로 동기화할 수 있습니다.');
            };

            const loadState = () => {
                const savedProvider = localStorage.getItem('ai_provider') || 'gemini';
                state.provider = savedProvider;
                els.providerSelect.value = savedProvider;
                state.storageMode = localStorage.getItem('ai_storage_mode') || 'local';
                els.storageMode.value = state.storageMode;
                state.apiKey = readStorage(providerKeyStorageKey(savedProvider)) || '';
                state.model = readStorage(providerModelStorageKey(savedProvider)) || '';
                els.apiKeyInput.value = state.apiKey;
                els.modelInput.value = state.model;
            };

            const applyProviderUI = () => {
                const config = PROVIDER_CONFIG[state.provider];
                els.apiKeyLabel.textContent = config.label + ':';
                els.apiKeyInput.placeholder = config.keyPlaceholder;
                refreshModelOptions();
            };

            const refreshModelOptions = () => {
                const models = (PROVIDER_MODELS[state.provider] || []).filter(m => isCompatibleTextModel(state.provider, m));
                const current = state.model || PROVIDER_CONFIG[state.provider].defaultModel;
                els.modelInput.replaceChildren();
                models.forEach((modelName) => {
                    const opt = document.createElement('option');
                    opt.value = modelName;
                    opt.textContent = modelName;
                    els.modelInput.appendChild(opt);
                });
                if (!models.includes(current) && current) {
                    const opt = document.createElement('option');
                    opt.value = current;
                    opt.textContent = `${current} (저장된 값)`;
                    els.modelInput.prepend(opt);
                }
                els.modelInput.value = current || models[0] || '';
                state.model = els.modelInput.value;
            };

            const setModelSourceHelp = (text) => {
                if (els.modelSourceHelp) els.modelSourceHelp.textContent = text;
            };

            const updateProviderModels = (provider, models) => {
                if (!Array.isArray(models) || !models.length) return;
                PROVIDER_MODELS[provider] = [...new Set(models)].filter(m => isCompatibleTextModel(provider, m));
                if (state.provider === provider) refreshModelOptions();
            };

            const fetchGeminiModels = async (apiKey) => {
                const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models?key=${apiKey}`);
                if (!res.ok) throw new Error(`Gemini 모델 조회 실패 (HTTP ${res.status})`);
                const data = await res.json();
                const models = (data.models || [])
                    .map(m => (m.name || '').replace('models/', ''))
                    .filter(name => isCompatibleTextModel('gemini', name));
                return [...new Set(models)].sort();
            };

            const fetchOpenAIModels = async (apiKey) => {
                const res = await fetch('https://api.openai.com/v1/models', {
                    headers: { 'Authorization': `Bearer ${apiKey}` }
                });
                if (!res.ok) throw new Error(`OpenAI 모델 조회 실패 (HTTP ${res.status})`);
                const data = await res.json();
                const models = (data.data || [])
                    .map(m => m.id)
                    .filter(Boolean)
                    .filter(id => isCompatibleTextModel('openai', id));
                return [...new Set(models)].sort();
            };

            const fetchModelsByProvider = async () => {
                const provider = state.provider;
                const apiKey = els.apiKeyInput.value.trim();
                if (!apiKey) {
                    alert(`${PROVIDER_CONFIG[provider].label}를 먼저 입력해주세요.`);
                    els.apiKeyInput.focus();
                    return;
                }
                try {
                    els.refreshModelsBtn.disabled = true;
                    els.refreshModelsBtn.textContent = '불러오는 중...';
                    let models = [];
                    if (provider === 'gemini') {
                        models = await fetchGeminiModels(apiKey);
                        setModelSourceHelp(`* Gemini API에서 텍스트 호환 모델 ${models.length}개를 불러왔습니다.`);
                    } else if (provider === 'openai') {
                        models = await fetchOpenAIModels(apiKey);
                        setModelSourceHelp(`* OpenAI API에서 텍스트 호환 모델 ${models.length}개를 불러왔습니다.`);
                    } else {
                        // Anthropic은 모델 목록 API 제한 이슈가 있어 안전한 기본 목록 유지
                        models = PROVIDER_MODELS.anthropic;
                        setModelSourceHelp('* Anthropic은 기본 모델 목록을 사용합니다.');
                    }
                    updateProviderModels(provider, models);
                } catch (e) {
                    setModelSourceHelp(`* 모델 조회 실패: ${e.message} (기본 목록 사용)`);
                    alert(`모델 조회 실패: ${e.message}`);
                } finally {
                    els.refreshModelsBtn.disabled = false;
                    els.refreshModelsBtn.textContent = '모델 불러오기';
                }
            };

            const parseVersionTuple = (name) => ((name.match(/\d+(?:\.\d+)*/g) || []).flatMap(s => s.split('.').map(n => parseInt(n, 10)))).length ? ((name.match(/\d+(?:\.\d+)*/g) || []).flatMap(s => s.split('.').map(n => parseInt(n, 10)))) : [0];
            const compareVersionTupleDesc = (a, b) => {
                const av = parseVersionTuple(a), bv = parseVersionTuple(b), len = Math.max(av.length, bv.length);
                for (let i = 0; i < len; i++) { const ai = av[i] ?? 0, bi = bv[i] ?? 0; if (ai !== bi) return bi - ai; }
                return a.length - b.length;
            };

            const getLatestFlashModel = async (apiKey) => {
                const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models?key=${apiKey}`);
                if (!res.ok) throw new Error(`Gemini 모델 조회 실패 (HTTP ${res.status})`);
                const data = await res.json();
                const models = (data.models || []).map(m => (m.name || '').replace('models/', '')).filter(Boolean);
                const flashModels = models.filter(name => name.includes('flash') && !name.includes('preview') && !name.includes('experimental'));
                const candidates = flashModels.length ? flashModels : models.filter(name => name.includes('flash'));
                if (!candidates.length) throw new Error('사용 가능한 Gemini flash 모델을 찾지 못했습니다.');
                candidates.sort(compareVersionTupleDesc);
                return candidates[0];
            };
            const getLatestFlashModelCached = async (apiKey) => {
                const cached = localStorage.getItem(MODEL_CACHE_KEY);
                const cachedAt = parseInt(localStorage.getItem(MODEL_CACHE_AT_KEY) || '0', 10);
                if (cached && cachedAt && (Date.now() - cachedAt) < MODEL_CACHE_TTL_MS) return cached;
                const latest = await getLatestFlashModel(apiKey);
                localStorage.setItem(MODEL_CACHE_KEY, latest);
                localStorage.setItem(MODEL_CACHE_AT_KEY, String(Date.now()));
                return latest;
            };

            const buildPrompt = (text) => {
                const currentYear = new Date().getFullYear();
                return `Context: You are a data parsing assistant.
Goal: Parse the user's unstructured work log text into a strictly formatted JSON array.
Current Year: ${currentYear} (Use this year if year is missing in input)

Input Text:
"""
${text}
"""

Requirements:
1. Identify dates and time ranges. Handle formats like "10,22", "10/22", "10월 22일".
2. Handle time formats like "11:30", "14:00", "2pm".
3. Group multiple sessions for the same date into one object.
4. Calculate 'durationMinutes' for each session.
5. Calculate 'totalMinutes' for the day.
6. Identify the Day of the Week (Korean string: 월/화/수/목/금/토/일) for each date.
7. Sort by date ascending.
8. Output strictly valid JSON only. No markdown code blocks, no explanations.

Output JSON Structure:
[
  {
    "date": "YYYY-MM-DD",
    "dayOfWeek": "Korean string (e.g. '월', '화', '수')",
    "sessions": [
      { "start": "HH:MM", "end": "HH:MM", "durationMinutes": number, "note": "optional text if present" }
    ],
    "totalMinutes": number
  }
]`;
            };

            const normalizeJsonFromText = (rawText) => {
                let cleaned = (rawText || '').replace(/^```json\s*/i, '').replace(/\s*```\s*$/i, '').trim();
                const jsonMatch = cleaned.match(/(\[[\s\S]*\]|\{[\s\S]*\})/);
                const jsonText = (jsonMatch ? jsonMatch[1] : cleaned).trim();
                return JSON.parse(jsonText);
            };

            const callGemini = async ({ apiKey, model, prompt }) => {
                const modelName = model || await getLatestFlashModelCached(apiKey);
                const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${modelName}:generateContent?key=${apiKey}`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ contents: [{ parts: [{ text: prompt }] }], generationConfig: { temperature: 0.1 } })
                });
                if (!response.ok) throw new Error(`Gemini 호출 실패 (HTTP ${response.status})`);
                const data = await response.json();
                const rawText = data?.candidates?.[0]?.content?.parts?.[0]?.text;
                if (!rawText) throw new Error('Gemini 응답이 비어 있습니다.');
                return { rawText, modelName };
            };
            const callOpenAI = async ({ apiKey, model, prompt }) => {
                const modelName = model || PROVIDER_CONFIG.openai.defaultModel;
                const response = await fetch('https://api.openai.com/v1/chat/completions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${apiKey}` },
                    body: JSON.stringify({ model: modelName, temperature: 0.1, messages: [{ role: 'user', content: prompt }] })
                });
                if (!response.ok) throw new Error(`OpenAI 호출 실패 (HTTP ${response.status}): ${await response.text()}`);
                const data = await response.json();
                const rawText = data?.choices?.[0]?.message?.content;
                if (!rawText) throw new Error('OpenAI 응답이 비어 있습니다.');
                return { rawText, modelName };
            };
            const callAnthropic = async ({ apiKey, model, prompt }) => {
                const modelName = model || PROVIDER_CONFIG.anthropic.defaultModel;
                const response = await fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-api-key': apiKey, 'anthropic-version': '2023-06-01' },
                    body: JSON.stringify({ model: modelName, max_tokens: 2048, temperature: 0.1, messages: [{ role: 'user', content: prompt }] })
                });
                if (!response.ok) throw new Error(`Anthropic 호출 실패 (HTTP ${response.status}): ${await response.text()}`);
                const data = await response.json();
                const rawText = (data?.content || []).map(c => c?.text || '').join('\n').trim();
                if (!rawText) throw new Error('Anthropic 응답이 비어 있습니다.');
                return { rawText, modelName };
            };

            const parseWithAI = async () => {
                const text = els.input.value.trim();
                const apiKey = els.apiKeyInput.value.trim();
                const model = els.modelInput.value;
                const provider = els.providerSelect.value;
                if (!apiKey) { alert(`${PROVIDER_CONFIG[provider].label}를 입력해주세요.`); els.apiKeyInput.focus(); return; }
                if (!text) { alert('변환할 텍스트를 입력해주세요.'); els.input.focus(); return; }
                state.provider = provider; state.apiKey = apiKey; state.model = model;
                localStorage.setItem('ai_provider', provider);
                writeStorage(providerKeyStorageKey(provider), apiKey);
                writeStorage(providerModelStorageKey(provider), model);
                const prompt = buildPrompt(text);
                setLoading(true);
                try {
                    const result = provider === 'gemini' ? await callGemini({ apiKey, model, prompt }) : provider === 'openai' ? await callOpenAI({ apiKey, model, prompt }) : await callAnthropic({ apiKey, model, prompt });
                    state.parsedData = normalizeJsonFromText(result.rawText);
                    render();
                } catch (error) {
                    console.error('AI Parsing Error:', error);
                    const friendly = mapErrorMessage(error);
                    const errorBox = document.createElement('div');
                    errorBox.className = 'error-msg';
                    const title = document.createElement('strong');
                    title.textContent = '오류 발생:';
                    const br1 = document.createElement('br');
                    const message = document.createElement('span');
                    message.textContent = friendly;
                    const br2 = document.createElement('br');
                    const br3 = document.createElement('br');
                    const retryBtn = document.createElement('button');
                    retryBtn.id = 'retry-btn';
                    retryBtn.className = 'btn-outline';
                    retryBtn.style.marginTop = '8px';
                    retryBtn.textContent = '다시 시도';
                    errorBox.append(title, br1, message, br2, br3, retryBtn);
                    els.outputContainer.replaceChildren(errorBox);
                    retryBtn.addEventListener('click', parseWithAI);
                } finally { setLoading(false); }
            };

            const mapErrorMessage = (error) => {
                const msg = String(error?.message || '알 수 없는 오류');
                if (msg.includes('401') || msg.includes('403')) return '인증 실패: API 키 권한 또는 키 값을 확인해주세요.';
                if (msg.includes('404')) return '모델 또는 엔드포인트를 찾지 못했습니다. 모델명을 확인해주세요.';
                if (msg.includes('429')) return '요청 한도를 초과했습니다. 잠시 후 다시 시도해주세요.';
                if (msg.includes('500') || msg.includes('502') || msg.includes('503')) return 'AI 서비스 서버 오류입니다. 잠시 후 다시 시도해주세요.';
                if (msg.toLowerCase().includes('failed to fetch')) return '네트워크 연결 오류입니다. 인터넷/방화벽 상태를 확인해주세요.';
                return msg;
            };

            const setLoading = (isLoading) => {
                state.isLoading = isLoading;
                if (isLoading) {
                    els.loadingOverlay.classList.remove('hidden');
                    els.convertBtn.disabled = true;
                    els.convertBtn.innerHTML = '<span>분석 중...</span>';
                } else {
                    els.loadingOverlay.classList.add('hidden');
                    els.convertBtn.disabled = false;
                    els.convertBtn.innerHTML = '<span>AI 변환 실행</span>';
                }
            };

            // --- 3. Rendering ---
            const render = () => {
                const { parsedData, currentView } = state;

                if (!parsedData) return;
                if (parsedData.length === 0) {
                    const empty = document.createElement('div');
                    empty.style.padding = '2rem';
                    empty.style.textAlign = 'center';
                    empty.textContent = '분석된 데이터가 없습니다.';
                    els.outputContainer.replaceChildren(empty);
                    return;
                }

                // Destroy existing chart if leaving chart view or re-rendering
                if (state.chartInstance) {
                    state.chartInstance.destroy();
                    state.chartInstance = null;
                }

                if (currentView === 'json') {
                    renderJSON();
                } else if (currentView === 'table') {
                    renderTable();
                } else if (currentView === 'chart') {
                    renderChart();
                }
            };

            const renderJSON = () => {
                const jsonString = JSON.stringify(state.parsedData, null, 2);
                const jsonOutput = document.createElement('div');
                jsonOutput.className = 'json-output';
                jsonOutput.textContent = jsonString;
                els.outputContainer.replaceChildren(jsonOutput);
            };

            const renderTable = () => {
                // 총 근무시간 합계 계산
                const grandTotalMinutes = state.parsedData.reduce((sum, item) => sum + item.totalMinutes, 0);

                const wrapper = document.createElement('div');
                wrapper.style.padding = '1rem';

                const totalSummary = document.createElement('div');
                totalSummary.className = 'total-summary';
                totalSummary.textContent = `총 근무 시간 합계: ${formatMinutes(grandTotalMinutes)}`;
                wrapper.appendChild(totalSummary);

                const table = document.createElement('table');
                table.className = 'table-view';

                const thead = document.createElement('thead');
                const headerRow = document.createElement('tr');
                const thDate = document.createElement('th');
                thDate.style.width = '140px';
                thDate.textContent = '날짜';
                const thSessions = document.createElement('th');
                thSessions.textContent = '세션 정보 (시간 / 메모)';
                const thTotal = document.createElement('th');
                thTotal.style.width = '100px';
                thTotal.textContent = '일일 합계';
                headerRow.append(thDate, thSessions, thTotal);
                thead.appendChild(headerRow);

                const tbody = document.createElement('tbody');
                state.parsedData.forEach((item) => {
                    const row = document.createElement('tr');

                    const dateCell = document.createElement('td');
                    const dateText = document.createTextNode(`${item.date || ''} `);
                    const dayBadge = document.createElement('span');
                    dayBadge.className = 'day-badge';
                    dayBadge.textContent = `(${item.dayOfWeek || '-'})`;
                    dateCell.append(dateText, dayBadge);

                    const sessionsCell = document.createElement('td');
                    (item.sessions || []).forEach((s) => {
                        const tag = document.createElement('div');
                        tag.className = 'tag';
                        tag.appendChild(document.createTextNode(`${s.start || ''} ~ ${s.end || ''} (${formatMinutes(s.durationMinutes || 0)})`));
                        if (s.note) {
                            const note = document.createElement('span');
                            note.style.color = '#666';
                            note.style.fontSize = '0.75rem';
                            note.textContent = `| ${s.note}`;
                            tag.appendChild(document.createTextNode(' '));
                            tag.appendChild(note);
                        }
                        sessionsCell.appendChild(tag);
                    });

                    const totalCell = document.createElement('td');
                    totalCell.style.fontWeight = 'bold';
                    totalCell.style.color = 'var(--primary)';
                    totalCell.textContent = formatMinutes(item.totalMinutes || 0);

                    row.append(dateCell, sessionsCell, totalCell);
                    tbody.appendChild(row);
                });

                table.append(thead, tbody);
                wrapper.appendChild(table);
                els.outputContainer.replaceChildren(wrapper);
            };

            const renderChart = () => {
                // 총 근무시간 합계 계산
                const grandTotalMinutes = state.parsedData.reduce((sum, item) => sum + item.totalMinutes, 0);

                els.outputContainer.innerHTML = `
                    <div class="chart-container">
                        <div class="total-summary">
                            🎉 총 근무 시간: ${formatMinutes(grandTotalMinutes)}
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="workChart"></canvas>
                        </div>
                    </div>
                `;

                const ctx = document.getElementById('workChart').getContext('2d');
                
                // 데이터 준비: 라벨에 요일 추가
                const labels = state.parsedData.map(d => `${d.date} (${d.dayOfWeek || ''})`);
                const dataMinutes = state.parsedData.map(d => d.totalMinutes);
                const dataHours = dataMinutes.map(m => (m / 60).toFixed(1)); // 시간 단위로 변환

                state.chartInstance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: '근무 시간 (시간)',
                            data: dataHours,
                            backgroundColor: 'rgba(59, 130, 246, 0.6)', // Primary color
                            borderColor: 'rgb(37, 99, 235)',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const rawMinutes = dataMinutes[context.dataIndex];
                                        return `${formatMinutes(rawMinutes)} (${context.raw}시간)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: '시간 (Hours)'
                                }
                            }
                        }
                    }
                });
            };

            const formatMinutes = (mins) => {
                const h = Math.floor(mins / 60);
                const m = mins % 60;
                if (h > 0) return `${h}시간 ${m}분`;
                return `${m}분`;
            };

            const toCsv = (data) => {
                const rows = [['date','dayOfWeek','start','end','durationMinutes','note','totalMinutes']];
                data.forEach(d => (d.sessions || []).forEach(s => rows.push([
                    d.date || '',
                    d.dayOfWeek || '',
                    s.start || '',
                    s.end || '',
                    String(s.durationMinutes ?? ''),
                    String(s.note || '').replaceAll('"', '""'),
                    String(d.totalMinutes ?? '')
                ])));
                return rows.map(r => r.map(v => `"${String(v).replaceAll('"','""')}"`).join(',')).join('\n');
            };

            const downloadFile = (filename, content, type='text/plain;charset=utf-8') => {
                const blob = new Blob([content], { type });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.click();
                URL.revokeObjectURL(url);
            };

            const tsFile = () => {
                const d = new Date();
                const p=(n)=>String(n).padStart(2,'0');
                return `${d.getFullYear()}${p(d.getMonth()+1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`;
            };


            // --- 4. Event Listeners ---
            const bindEvents = () => {
                els.convertBtn.addEventListener('click', parseWithAI);
                els.refreshModelsBtn.addEventListener('click', fetchModelsByProvider);

                els.providerSelect.addEventListener('change', (e) => {
                    state.provider = e.target.value;
                    localStorage.setItem('ai_provider', state.provider);
                    state.apiKey = readStorage(providerKeyStorageKey(state.provider)) || '';
                    state.model = readStorage(providerModelStorageKey(state.provider)) || '';
                    els.apiKeyInput.value = state.apiKey;
                    els.modelInput.value = state.model;
                    applyProviderUI();
                    setModelSourceHelp('* 모델 목록은 기본 내장값입니다. API Key 입력 후 "모델 불러오기"로 동기화할 수 있습니다.');
                });

                els.apiKeyInput.addEventListener('change', (e) => {
                    state.apiKey = e.target.value;
                    writeStorage(providerKeyStorageKey(state.provider), e.target.value);
                    if (state.provider === 'gemini') {
                        localStorage.removeItem(MODEL_CACHE_KEY);
                        localStorage.removeItem(MODEL_CACHE_AT_KEY);
                    }
                });

                els.modelInput.addEventListener('change', (e) => {
                    state.model = e.target.value;
                    writeStorage(providerModelStorageKey(state.provider), state.model);
                });

                els.tabs.forEach(tab => {
                    tab.addEventListener('click', (e) => {
                        els.tabs.forEach(t => t.classList.remove('active'));
                        e.target.classList.add('active');
                        state.currentView = e.target.dataset.view;
                        render();
                    });
                });

                els.copyBtn.addEventListener('click', () => {
                    if (!state.parsedData) return;
                    // 차트 뷰에서는 복사가 애매하므로 JSON을 복사하도록 함
                    const textToCopy = JSON.stringify(state.parsedData, null, 2);
                    navigator.clipboard.writeText(textToCopy).then(() => {
                        const originalText = els.copyBtn.innerText;
                        els.copyBtn.innerText = "JSON 복사됨!";
                        setTimeout(() => els.copyBtn.innerText = originalText, 2000);
                    });
                });

                els.storageMode.addEventListener('change', (e) => {
                    const prevMode = state.storageMode;
                    state.storageMode = e.target.value;
                    localStorage.setItem('ai_storage_mode', state.storageMode);

                    const keyK = providerKeyStorageKey(state.provider);
                    const modelK = providerModelStorageKey(state.provider);
                    const prevRead = (k) => prevMode === 'session' ? sessionStorage.getItem(k) : localStorage.getItem(k);
                    const keyVal = prevRead(keyK) || state.apiKey || '';
                    const modelVal = prevRead(modelK) || state.model || '';

                    clearStorageForKey(keyK);
                    clearStorageForKey(modelK);

                    if (state.storageMode !== 'none') {
                        writeStorage(keyK, keyVal);
                        writeStorage(modelK, modelVal);
                    }
                });

                els.applyPresetBtn.addEventListener('click', () => {
                    const v = els.presetSelect.value;
                    if (!v || !SAMPLE_INPUTS[v]) return;
                    if (els.input.value.trim() && !confirm('현재 입력 내용을 예시로 덮어쓸까요?')) return;
                    els.input.value = SAMPLE_INPUTS[v];
                });

                els.downloadJsonBtn.addEventListener('click', () => {
                    if (!state.parsedData) return;
                    downloadFile(`worklog-${tsFile()}.json`, JSON.stringify(state.parsedData, null, 2), 'application/json;charset=utf-8');
                });

                els.downloadCsvBtn.addEventListener('click', () => {
                    if (!state.parsedData) return;
                    downloadFile(`worklog-${tsFile()}.csv`, '\ufeff' + toCsv(state.parsedData), 'text/csv;charset=utf-8');
                });

                els.clearBtn.addEventListener('click', () => {
                    els.input.value = '';
                    els.input.focus();
                });
            };

            return { init };
        })();

        document.addEventListener('DOMContentLoaded', App.init);

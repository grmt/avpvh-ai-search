(function() {
	const config = globalThis.gewebChatArchive || {};
	const root = document.querySelector('.geweb-chat-archive');
	if (!root) {
		return;
	}
	const state = {documents: [], activePath: '', rawVisible: false};
	const elements = {
		document: root.querySelector('.geweb-chat-document'),
		documents: root.querySelector('.geweb-chat-documents'),
		empty: root.querySelector('.geweb-chat-empty'),
		form: root.querySelector('.geweb-chat-search'),
		input: root.querySelector('#geweb-chat-query'),
		meta: root.querySelector('.geweb-chat-meta'),
		participants: root.querySelector('.geweb-chat-participants'),
		raw: root.querySelector('.geweb-chat-raw'),
		rendered: root.querySelector('.geweb-chat-rendered'),
		summary: root.querySelector('.geweb-chat-summary'),
		title: root.querySelector('.geweb-chat-title'),
		toggle: root.querySelector('.geweb-chat-toggle'),
	};

	function request(action, fields = {}) {
		const body = new URLSearchParams({action, nonce: config.nonce || '', ...fields});
		return fetch(config.ajaxUrl || '', {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString(),
		}).then(async (response) => {
			const payload = await response.json();
			if (!response.ok || !payload.success) {
				throw new Error(payload?.data?.message || `Request failed (${response.status})`);
			}
			return payload.data;
		});
	}

	function titleFor(item) {
		return String(item?.display_title || item?.title || 'Chat');
	}

	function participantText(item) {
		return Array.isArray(item?.participants) ? item.participants.join(', ') : '';
	}

	function createButton(item) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'geweb-chat-link';
		button.dataset.path = String(item.path || '');
		button.classList.toggle('active', button.dataset.path === state.activePath);
		const title = document.createElement('strong');
		title.textContent = titleFor(item);
		const details = document.createElement('span');
		const part = Number(item.part_count || 1) > 1 ? ` · deel ${item.part}/${item.part_count}` : '';
		const visibility = item.visibility === 'private' ? ' · PRIVÉ' : '';
		details.textContent = `${participantText(item)} · ${item.indexed_messages || 0} berichten${part}${visibility}`;
		button.append(title, details);
		if (item.snippet) {
			const snippet = document.createElement('small');
			snippet.textContent = String(item.snippet);
			button.append(snippet);
		}
		button.addEventListener('click', () => loadDocument(button.dataset.path));
		return button;
	}

	function renderList(items, label) {
		elements.documents.replaceChildren();
		items.forEach((item) => {
			const row = document.createElement('li');
			row.append(createButton(item));
			elements.documents.append(row);
		});
		elements.summary.textContent = `${items.length} ${label}`;
	}

	function stripFrontmatter(markdown) {
		return String(markdown || '').replace(/^---\s*\n[\s\S]*?\n---\s*\n/, '');
	}

	async function loadDocument(path) {
		const payload = await request('geweb_chat_archive_document', {path});
		const item = payload.document || {};
		const content = String(payload.content || '');
		state.activePath = path;
		elements.title.textContent = titleFor(item);
		elements.participants.textContent = participantText(item);
		elements.meta.textContent = `${item.indexed_messages || 0} berichten · ${item.visibility || 'internal'}`;
		elements.raw.textContent = content;
		elements.rendered.innerHTML = globalThis.GewebAisearchMarkdown.render(stripFrontmatter(content));
		elements.empty.hidden = true;
		elements.document.hidden = false;
		root.querySelectorAll('.geweb-chat-link').forEach((button) => {
			button.classList.toggle('active', button.dataset.path === state.activePath);
		});
	}

	async function search(query) {
		if (!query) {
			renderList(state.documents, 'documenten');
			return;
		}
		elements.summary.textContent = 'Zoeken…';
		const results = await request('geweb_chat_archive_search', {query});
		renderList(results, results.length === 1 ? 'resultaat' : 'resultaten');
	}

	function toggleRaw() {
		state.rawVisible = !state.rawVisible;
		elements.raw.hidden = !state.rawVisible;
		elements.rendered.hidden = state.rawVisible;
		elements.toggle.textContent = state.rawVisible ? 'Opgemaakte tekst' : 'Ruwe Markdown';
	}

	function showError(error) {
		elements.summary.textContent = error instanceof Error ? error.message : 'Onbekende fout';
	}

	request('geweb_chat_archive_list').then((documents) => {
		state.documents = documents;
		renderList(documents, 'documenten');
	}).catch(showError);
	elements.form.addEventListener('submit', (event) => {
		event.preventDefault();
		search(elements.input.value.trim()).catch(showError);
	});
	elements.toggle.addEventListener('click', toggleRaw);
})();

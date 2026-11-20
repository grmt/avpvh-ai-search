(function() {
	const state = {
		documents: [],
		activePath: '',
		rawVisible: false,
	};
	const elements = {
		document: document.querySelector('#document'),
		documents: document.querySelector('#documents'),
		emptyState: document.querySelector('#empty-state'),
		meta: document.querySelector('#document-meta'),
		participants: document.querySelector('#document-participants'),
		raw: document.querySelector('#raw'),
		rendered: document.querySelector('#rendered'),
		search: document.querySelector('#search'),
		searchForm: document.querySelector('#search-form'),
		summary: document.querySelector('#summary'),
		title: document.querySelector('#document-title'),
		toggleRaw: document.querySelector('#toggle-raw'),
	};

	function displayTitle(item) {
		const title = String(item?.display_title || item?.title || '').trim();
		return !title || title.toLowerCase() === 'null' ? 'Call logs' : title;
	}

	function participantText(item) {
		return Array.isArray(item?.participants) ? item.participants.join(', ') : '';
	}

	function stripFrontmatter(markdown) {
		return String(markdown || '').replace(/^---\s*\n[\s\S]*?\n---\s*\n/, '');
	}

	function createDocumentButton(item) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'document-link';
		button.dataset.path = String(item.path || '');
		button.classList.toggle('active', button.dataset.path === state.activePath);
		const title = document.createElement('strong');
		title.textContent = displayTitle(item);
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

	function renderDocumentList(items, label) {
		elements.documents.replaceChildren();
		items.forEach((item) => {
			const listItem = document.createElement('li');
			listItem.append(createDocumentButton(item));
			elements.documents.append(listItem);
		});
		elements.summary.textContent = `${items.length} ${label}`;
	}

	async function requestJson(url) {
		const response = await fetch(url, {headers: {'Accept': 'application/json'}});
		if (!response.ok) {
			throw new Error(`Request failed (${response.status})`);
		}
		return response.json();
	}

	async function loadDocument(path) {
		const payload = await requestJson(`/api/document?path=${encodeURIComponent(path)}`);
		const item = payload.document || {};
		const content = String(payload.content || '');
		state.activePath = path;
		elements.title.textContent = displayTitle(item);
		elements.participants.textContent = participantText(item);
		const visibility = item.visibility === 'private' ? 'privé' : 'intern';
		elements.meta.textContent = `${item.indexed_messages || 0} berichten · ${visibility} · ${item.path || ''}`;
		elements.raw.textContent = content;
		elements.rendered.innerHTML = globalThis.GewebAisearchMarkdown.render(stripFrontmatter(content));
		elements.emptyState.hidden = true;
		elements.document.hidden = false;
		document.querySelectorAll('.document-link').forEach((button) => {
			button.classList.toggle('active', button.dataset.path === state.activePath);
		});
	}

	async function searchDocuments(query) {
		if (!query) {
			renderDocumentList(state.documents, 'documenten');
			return;
		}
		elements.summary.textContent = 'Zoeken…';
		const results = await requestJson(`/api/search?q=${encodeURIComponent(query)}`);
		renderDocumentList(results, results.length === 1 ? 'resultaat' : 'resultaten (maximaal 100)');
	}

	function toggleRawView() {
		state.rawVisible = !state.rawVisible;
		elements.raw.hidden = !state.rawVisible;
		elements.rendered.hidden = state.rawVisible;
		elements.toggleRaw.textContent = state.rawVisible ? 'Opgemaakte tekst' : 'Ruwe Markdown';
	}

	async function initialize() {
		state.documents = await requestJson('/api/documents');
		renderDocumentList(state.documents, 'documenten');
		elements.searchForm.addEventListener('submit', (event) => {
			event.preventDefault();
			searchDocuments(elements.search.value.trim()).catch(showError);
		});
		elements.toggleRaw.addEventListener('click', toggleRawView);
	}

	function showError(error) {
		elements.summary.textContent = error instanceof Error ? error.message : 'Onbekende fout';
	}

	initialize().catch(showError);
})();

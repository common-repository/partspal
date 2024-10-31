//<script src="runtime-es2015.js" type="module"></script>
// <script src="runtime-es5.js" nomodule defer></script>
// <script src="polyfills-es5.js" nomodule defer></script>
// <script src="polyfills-es2015.js" type="module"></script>
// <script src="vendor-es2015.js" type="module"></script>
// <script src="vendor-es5.js" nomodule defer></script>
// <script src="main-es2015.js" type="module"></script>
// <script src="main-es5.js" nomodule defer></script>

/*
The PartsPal WordPress plugin is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

The PartsPal WordPress plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with the PartsPal WordPress plugin. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

(function (document, window) {
	const CONFIG_NAME = 'PARTSPAL_APP_CONFIGS';
	// FIXME this is currently the only difference between prod version and staging version
	// try think of a way to handle this
	//	const ELEMENT_PREFIX = 'https://proxy.partly.pro/shopify/';
	const ELEMENT_PREFIX = 'https://proxy.partly.co.nz/shopify/';
	const rand = Math.random() * 1000;

	function docReady(fn) {
		// see if DOM is already available
		if (document.readyState === 'complete' || document.readyState === 'interactive') {
			// call on next available tick
			setTimeout(fn, 1);
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function loadJSON(url, onSuccess) {
		const xhr = new XMLHttpRequest();
		xhr.open('GET', url);
		xhr.responseType = 'json';

		xhr.onload = () => {
			if (xhr.status === 200) {
				onSuccess(xhr.response);
			}
		};
		xhr.send();
	}

	//	function prefetch(url, type) {
	//		const linkEl = document.createElement('link');
	//		linkEl.rel = 'prefetch';
	//		linkEl.href = url;
	//		linkEl.as = type;
	//		document.head.appendChild(linkEl);
	//	}

	function loadLink(url) {
		const linkEl = document.createElement('link');
		linkEl.rel = 'stylesheet';
		linkEl.href = url;
		document.head.appendChild(linkEl);
	}

	function loadScript(url, isModule) {
		const scriptEl = document.createElement('script');
		scriptEl.src = url;
		if (isModule) {
			scriptEl.type = 'module';
		} else {
			scriptEl.noModule = true;
			scriptEl.defer = true;
		}
		document.head.appendChild(scriptEl);
	}

	function initNg() {
		// root element is required for angular to initialize
		docReady(() => {
			const angularRoot = document.querySelector('ag-root');
			// this is app proxy page, don't do anything
			if (angularRoot) {
				return;
			}

			loadJSON(`${ELEMENT_PREFIX}meta.json?v=${rand}`, meta => {
				if (!Array.isArray(meta)) {
					console.error('failed to load meta.json');
					return;
				}
				const config = window[CONFIG_NAME];

				const stylesInfo = meta.find(each => each.name === 'styles');
				if (stylesInfo) {
					loadLink(`${ELEMENT_PREFIX}${stylesInfo.files[0]}`);
				}
				if (config && config.theme) {
					const themeInfo = meta.find(each => each.name === config.theme);
					if (themeInfo) {
						loadLink(`${ELEMENT_PREFIX}${themeInfo.files[0]}`);
					}
				}

				const rootEl = document.createElement('ag-root');
				rootEl.style.display = 'none';
				document.body.appendChild(rootEl);

				meta
					.filter(each => each.type === '.js')
					.forEach(jsInfo => {
						jsInfo.files.forEach(file => {
							const isModule = file.indexOf('-es2015') > -1;
							loadScript(`${ELEMENT_PREFIX}${file}`, isModule);
						});
					});
			});
		});
	}

	function hijackLinks() {
		const appConfig = window[CONFIG_NAME] || {};
		const basePrefix = appConfig.baseUrl || '/a/partspal';
		const links = document.querySelectorAll(`a[href*="${basePrefix}/"]`);

		links.forEach(each => {
			each.addEventListener(
				'click',
				e => {
					e.preventDefault();
					const links = each.href.split(basePrefix);
					const href = links[1];
					if (!window.partspalBridge) {
						document.addEventListener('ag:vanillaBridge:init', () => {
							window.partspalBridge.navigateByUrl(href);
						});
					} else {
						window.partspalBridge.navigateByUrl(href);
					}
					// using capture, this should be a bit faster than other event
				},
				true
			);
		});
	}

	scriptParams.navigationCallback = Function('url', scriptParams.navigationCallback);
	window[CONFIG_NAME] = scriptParams;
	if (window.location.href.includes(scriptParams.baseUrl)) window.location.replace('#partspal'); // force angular to load
	initNg();
	hijackLinks();
})(document, window);

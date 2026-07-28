/**
 * Lingcoo WebSocket Push 文档交互脚本
 */

document.addEventListener('DOMContentLoaded', function() {
    // 初始化所有功能
    initNavigation();
    initCodeBlocks();
    initScrollToTop();
    initSearchFunctionality();
    initThemeToggle();
    initTroubleshootingItems();
});

/**
 * 导航功能
 */
function initNavigation() {
    const navLinks = document.querySelectorAll('.nav-link');
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';
    
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'index.html')) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
}

/**
 * 代码块功能
 */
function initCodeBlocks() {
    const codeBlocks = document.querySelectorAll('pre code');
    
    codeBlocks.forEach(block => {
        // 添加复制按钮
        addCopyButton(block);
        
        // 添加语言标签
        addLanguageLabel(block);
        
        // 语法高亮（简单版本）
        highlightSyntax(block);
    });
}

function addCopyButton(codeBlock) {
    const pre = codeBlock.parentElement;
    const button = document.createElement('button');
    button.className = 'copy-button';
    button.textContent = '复制';
    button.title = '复制代码';
    
    button.addEventListener('click', function() {
        navigator.clipboard.writeText(codeBlock.textContent).then(() => {
            button.textContent = '已复制!';
            button.classList.add('copied');
            
            setTimeout(() => {
                button.textContent = '复制';
                button.classList.remove('copied');
            }, 2000);
        }).catch(() => {
            // 降级方案
            const textArea = document.createElement('textarea');
            textArea.value = codeBlock.textContent;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            
            button.textContent = '已复制!';
            setTimeout(() => {
                button.textContent = '复制';
            }, 2000);
        });
    });
    
    pre.style.position = 'relative';
    pre.appendChild(button);
}

function addLanguageLabel(codeBlock) {
    const text = codeBlock.textContent.trim();
    let language = 'text';
    
    if (text.startsWith('<?php')) {
        language = 'PHP';
    } else if (text.includes('function') && text.includes('=>')) {
        language = 'JavaScript';
    } else if (text.includes('define(') || text.includes('add_action(')) {
        language = 'PHP';
    } else if (text.includes('import') && text.includes('from')) {
        language = 'JavaScript';
    } else if (text.includes('POST') || text.includes('GET') || text.includes('Headers:')) {
        language = 'HTTP';
    } else if (text.includes('wp-content') || text.includes('├──')) {
        language = 'Shell';
    }
    
    if (language !== 'text') {
        const label = document.createElement('span');
        label.className = 'language-label';
        label.textContent = language;
        
        const pre = codeBlock.parentElement;
        pre.appendChild(label);
    }
}

function highlightSyntax(codeBlock) {
    let html = codeBlock.innerHTML;
    
    // PHP 语法高亮
    html = html.replace(/(&lt;\?php)/g, '<span class="php-tag">$1</span>');
    html = html.replace(/(function|class|public|private|protected|static|return|if|else|foreach|while|for)/g, '<span class="keyword">$1</span>');
    html = html.replace(/(\$\w+)/g, '<span class="variable">$1</span>');
    html = html.replace(/(\/\/.*$)/gm, '<span class="comment">$1</span>');
    html = html.replace(/(\/\*[\s\S]*?\*\/)/g, '<span class="comment">$1</span>');
    
    // JavaScript 语法高亮
    html = html.replace(/(const|let|var|function|return|if|else|for|while|import|export|from)/g, '<span class="keyword">$1</span>');
    html = html.replace(/(\/\/.*$)/gm, '<span class="comment">$1</span>');
    
    // 字符串高亮
    html = html.replace(/('([^'\\]|\\.)*')/g, '<span class="string">$1</span>');
    html = html.replace(/("([^"\\]|\\.)*")/g, '<span class="string">$1</span>');
    
    codeBlock.innerHTML = html;
}

/**
 * 回到顶部功能
 */
function initScrollToTop() {
    const scrollButton = document.createElement('button');
    scrollButton.className = 'scroll-to-top';
    scrollButton.innerHTML = '↑';
    scrollButton.title = '回到顶部';
    scrollButton.style.display = 'none';
    
    document.body.appendChild(scrollButton);
    
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 300) {
            scrollButton.style.display = 'block';
        } else {
            scrollButton.style.display = 'none';
        }
    });
    
    scrollButton.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}

/**
 * 搜索功能
 */
function initSearchFunctionality() {
    // 创建搜索框
    const searchContainer = document.createElement('div');
    searchContainer.className = 'search-container';
    searchContainer.innerHTML = `
        <input type="text" class="search-input" placeholder="搜索文档..." />
        <div class="search-results"></div>
    `;
    
    const navigation = document.querySelector('.navigation');
    if (navigation) {
        navigation.appendChild(searchContainer);
    }
    
    const searchInput = document.querySelector('.search-input');
    const searchResults = document.querySelector('.search-results');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            
            if (query.length < 2) {
                searchResults.style.display = 'none';
                return;
            }
            
            performSearch(query, searchResults);
        });
        
        // 点击外部关闭搜索结果
        document.addEventListener('click', function(e) {
            if (!searchContainer.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });
    }
}

function performSearch(query, resultsContainer) {
    const searchableElements = document.querySelectorAll('h2, h3, h4, p, li, td');
    const results = [];
    
    searchableElements.forEach(element => {
        const text = element.textContent.toLowerCase();
        if (text.includes(query)) {
            const heading = findNearestHeading(element);
            results.push({
                element: element,
                heading: heading,
                text: element.textContent.trim().substring(0, 100) + '...'
            });
        }
    });
    
    displaySearchResults(results.slice(0, 5), resultsContainer, query);
}

function findNearestHeading(element) {
    let current = element;
    while (current && current !== document.body) {
        if (current.tagName && current.tagName.match(/^H[1-6]$/)) {
            return current.textContent;
        }
        current = current.previousElementSibling || current.parentElement;
    }
    return '文档内容';
}

function displaySearchResults(results, container, query) {
    if (results.length === 0) {
        container.innerHTML = '<div class="search-no-results">未找到相关内容</div>';
    } else {
        const html = results.map(result => `
            <div class="search-result-item" onclick="scrollToElement(this)" data-element="${getElementPath(result.element)}">
                <div class="search-result-heading">${result.heading}</div>
                <div class="search-result-text">${highlightQuery(result.text, query)}</div>
            </div>
        `).join('');
        
        container.innerHTML = html;
    }
    
    container.style.display = 'block';
}

function highlightQuery(text, query) {
    const regex = new RegExp(`(${query})`, 'gi');
    return text.replace(regex, '<mark>$1</mark>');
}

function getElementPath(element) {
    const path = [];
    let current = element;
    
    while (current && current !== document.body) {
        let selector = current.tagName.toLowerCase();
        if (current.id) {
            selector += '#' + current.id;
        } else if (current.className) {
            selector += '.' + current.className.split(' ')[0];
        }
        path.unshift(selector);
        current = current.parentElement;
    }
    
    return path.join(' > ');
}

function scrollToElement(resultItem) {
    const path = resultItem.getAttribute('data-element');
    // 简化版本：直接滚动到结果项对应的元素
    const searchResults = document.querySelector('.search-results');
    searchResults.style.display = 'none';
}

/**
 * 主题切换功能
 */
function initThemeToggle() {
    const themeToggle = document.createElement('button');
    themeToggle.className = 'theme-toggle';
    themeToggle.innerHTML = '🌙';
    themeToggle.title = '切换主题';
    
    const header = document.querySelector('.header-content');
    if (header) {
        header.appendChild(themeToggle);
    }
    
    // 检查本地存储的主题设置
    const savedTheme = localStorage.getItem('fd-docs-theme');
    if (savedTheme === 'dark') {
        document.body.classList.add('dark-theme');
        themeToggle.innerHTML = '☀️';
    }
    
    themeToggle.addEventListener('click', function() {
        document.body.classList.toggle('dark-theme');
        const isDark = document.body.classList.contains('dark-theme');
        
        themeToggle.innerHTML = isDark ? '☀️' : '🌙';
        localStorage.setItem('fd-docs-theme', isDark ? 'dark' : 'light');
    });
}

/**
 * 故障排除项目交互
 */
function initTroubleshootingItems() {
    const troubleshootItems = document.querySelectorAll('.troubleshoot-item');
    
    troubleshootItems.forEach(item => {
        const header = item.querySelector('h4');
        if (header) {
            header.style.cursor = 'pointer';
            header.addEventListener('click', function() {
                item.classList.toggle('expanded');
            });
        }
    });
}

/**
 * 工具函数
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// 平滑滚动到锚点
function smoothScrollToAnchor() {
    const links = document.querySelectorAll('a[href^="#"]');
    
    links.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href').substring(1);
            const targetElement = document.getElementById(targetId);
            
            if (targetElement) {
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

// 初始化平滑滚动
smoothScrollToAnchor();

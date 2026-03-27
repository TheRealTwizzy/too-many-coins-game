(function () {
    "use strict";

    function getCategories() {
        return Array.isArray(window.WIKI_CATEGORIES) ? window.WIKI_CATEGORIES : [];
    }

    function escapeHtml(input) {
        return String(input || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/\"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function inlineMarkdown(text) {
        var safe = escapeHtml(text);
        safe = safe.replace(/`([^`]+)`/g, "<code>$1</code>");
        safe = safe.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
        return safe;
    }

    function parseTable(lines, startIndex) {
        var i = startIndex;
        var tableLines = [];
        while (i < lines.length && /^\s*\|/.test(lines[i])) {
            tableLines.push(lines[i]);
            i += 1;
        }

        if (tableLines.length < 2) {
            return null;
        }

        var headerCells = tableLines[0].trim().replace(/^\||\|$/g, "").split("|").map(function (x) { return inlineMarkdown(x.trim()); });
        var sepLine = tableLines[1].trim();
        if (!/^\|?[\s:-]+\|[\s|:-]*$/.test(sepLine)) {
            return null;
        }

        var rows = [];
        for (var r = 2; r < tableLines.length; r += 1) {
            var cells = tableLines[r].trim().replace(/^\||\|$/g, "").split("|").map(function (x) { return inlineMarkdown(x.trim()); });
            rows.push(cells);
        }

        var html = "<table><thead><tr>" + headerCells.map(function (c) { return "<th>" + c + "</th>"; }).join("") + "</tr></thead><tbody>";
        for (var j = 0; j < rows.length; j += 1) {
            html += "<tr>" + rows[j].map(function (c) { return "<td>" + c + "</td>"; }).join("") + "</tr>";
        }
        html += "</tbody></table>";

        return { html: html, nextIndex: i };
    }

    function parseMarkdown(md) {
        var text = String(md || "").replace(/\r\n/g, "\n");
        var lines = text.split("\n");
        var out = [];
        var i = 0;

        function flushParagraph(buffer) {
            if (buffer.length === 0) {
                return;
            }
            out.push("<p>" + inlineMarkdown(buffer.join(" ")) + "</p>");
            buffer.length = 0;
        }

        while (i < lines.length) {
            var line = lines[i];
            var trimmed = line.trim();

            if (!trimmed) {
                i += 1;
                continue;
            }

            var table = parseTable(lines, i);
            if (table) {
                out.push(table.html);
                i = table.nextIndex;
                continue;
            }

            if (/^###\s+/.test(trimmed)) {
                out.push("<h4>" + inlineMarkdown(trimmed.replace(/^###\s+/, "")) + "</h4>");
                i += 1;
                continue;
            }

            if (/^##\s+/.test(trimmed)) {
                out.push("<h3>" + inlineMarkdown(trimmed.replace(/^##\s+/, "")) + "</h3>");
                i += 1;
                continue;
            }

            if (/^>\s+/.test(trimmed)) {
                out.push("<blockquote>" + inlineMarkdown(trimmed.replace(/^>\s+/, "")) + "</blockquote>");
                i += 1;
                continue;
            }

            if (/^[-*]\s+/.test(trimmed)) {
                var ul = [];
                while (i < lines.length && /^\s*[-*]\s+/.test(lines[i].trim())) {
                    ul.push("<li>" + inlineMarkdown(lines[i].trim().replace(/^[-*]\s+/, "")) + "</li>");
                    i += 1;
                }
                out.push("<ul class=\"wiki-list\">" + ul.join("") + "</ul>");
                continue;
            }

            if (/^\d+\.\s+/.test(trimmed)) {
                var ol = [];
                while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i].trim())) {
                    ol.push("<li>" + inlineMarkdown(lines[i].trim().replace(/^\d+\.\s+/, "")) + "</li>");
                    i += 1;
                }
                out.push("<ol class=\"wiki-list\">" + ol.join("") + "</ol>");
                continue;
            }

            var paragraph = [];
            while (i < lines.length) {
                var next = lines[i].trim();
                if (!next || /^\|/.test(next) || /^###\s+/.test(next) || /^##\s+/.test(next) || /^>\s+/.test(next) || /^[-*]\s+/.test(next) || /^\d+\.\s+/.test(next)) {
                    break;
                }
                paragraph.push(next);
                i += 1;
            }
            flushParagraph(paragraph);
        }

        return out.join("\n");
    }

    function categoryToRoute(categoryId) {
        var map = {
            "getting-started": "getting-started",
            "gameplay": "game-systems",
            "competition": "competition",
            "social": "social",
            "strategy": "strategy"
        };
        return map[categoryId] || categoryId;
    }

    function buildChapterHtml(chapter) {
        var sectionHtml = (chapter.sections || []).map(function (section) {
            return [
                "<section class=\"wiki-section\" id=\"" + escapeHtml(section.id) + "\">",
                "<h4>" + escapeHtml(section.title) + "</h4>",
                parseMarkdown(section.content),
                "</section>"
            ].join("\n");
        }).join("\n");

        return [
            "<article class=\"wiki-chapter\" id=\"" + escapeHtml(chapter.id) + "\">",
            "<span class=\"wiki-chip\">Chapter " + escapeHtml(String(chapter.number)) + "</span>",
            "<h3>" + escapeHtml(chapter.title) + "</h3>",
            "<p class=\"wiki-muted\">" + escapeHtml(chapter.description || "") + "</p>",
            sectionHtml,
            "</article>"
        ].join("\n");
    }

    function initCategoryPage(categoryId) {
        var categories = getCategories();
        var category = categories.find(function (c) { return c.id === categoryId; });
        if (!category) {
            return;
        }

        var titleEl = document.getElementById("category-title");
        if (titleEl) {
            titleEl.textContent = category.title;
        }

        var subtitleEl = document.getElementById("category-subtitle");
        if (subtitleEl) {
            subtitleEl.textContent = "Full source migration from imported wiki ZIP.";
        }

        var contentEl = document.getElementById("category-content");
        if (contentEl) {
            contentEl.innerHTML = (category.chapters || []).map(buildChapterHtml).join("\n");
        }

        var jumpEl = document.getElementById("category-jump");
        if (jumpEl) {
            jumpEl.innerHTML = (category.chapters || []).map(function (ch) {
                return "<a class=\"wiki-quick-link\" href=\"#" + escapeHtml(ch.id) + "\"><strong>" + escapeHtml(ch.title) + "</strong><span>Chapter " + escapeHtml(String(ch.number)) + "</span></a>";
            }).join("");
        }
    }

    function getSearchResults(query) {
        var q = String(query || "").trim().toLowerCase();
        if (!q) {
            return [];
        }

        var categories = getCategories();
        var results = [];

        categories.forEach(function (category) {
            (category.chapters || []).forEach(function (chapter) {
                var chapterText = (chapter.title + " " + (chapter.description || "")).toLowerCase();
                if (chapterText.indexOf(q) >= 0) {
                    results.push({
                        categoryId: category.id,
                        chapterId: chapter.id,
                        sectionId: "",
                        chapterTitle: chapter.title,
                        sectionTitle: "",
                        snippet: chapter.description || chapter.title
                    });
                }

                (chapter.sections || []).forEach(function (section) {
                    var corpus = (section.title + " " + section.content).toLowerCase();
                    var idx = corpus.indexOf(q);
                    if (idx >= 0) {
                        var plain = String(section.content || "").replace(/\s+/g, " ").trim();
                        var start = Math.max(0, idx - 70);
                        var end = Math.min(plain.length, idx + q.length + 120);
                        var snippet = (start > 0 ? "..." : "") + plain.slice(start, end) + (end < plain.length ? "..." : "");
                        results.push({
                            categoryId: category.id,
                            chapterId: chapter.id,
                            sectionId: section.id,
                            chapterTitle: chapter.title,
                            sectionTitle: section.title,
                            snippet: snippet
                        });
                    }
                });
            });
        });

        return results;
    }

    function initSearchPage() {
        var input = document.getElementById("wiki-search-input");
        var resultsEl = document.getElementById("wiki-search-results");
        var stateEl = document.getElementById("wiki-search-state");

        if (!input || !resultsEl || !stateEl) {
            return;
        }

        function render(query) {
            var results = getSearchResults(query);
            if (!query.trim()) {
                stateEl.textContent = "Enter a term to search chapter titles and full section content.";
                resultsEl.innerHTML = "";
                return;
            }

            stateEl.textContent = "Found " + results.length + " result" + (results.length === 1 ? "" : "s") + ".";

            resultsEl.innerHTML = results.map(function (r) {
                var route = "/wiki/" + categoryToRoute(r.categoryId) + "/";
                var hash = r.sectionId ? ("#" + r.sectionId) : ("#" + r.chapterId);
                return [
                    "<article class=\"wiki-card\">",
                    "<h3><a href=\"" + route + hash + "\">" + escapeHtml(r.chapterTitle + (r.sectionTitle ? " - " + r.sectionTitle : "")) + "</a></h3>",
                    "<p class=\"wiki-muted\">" + escapeHtml(r.snippet) + "</p>",
                    "<p class=\"wiki-muted\">Path: " + escapeHtml(route + hash) + "</p>",
                    "</article>"
                ].join("\n");
            }).join("\n");
        }

        var params = new URLSearchParams(window.location.search);
        var initialQ = params.get("q") || "";
        input.value = initialQ;
        render(initialQ);

        input.addEventListener("input", function () {
            var q = input.value;
            var nextUrl = new URL(window.location.href);
            if (q.trim()) {
                nextUrl.searchParams.set("q", q);
            } else {
                nextUrl.searchParams.delete("q");
            }
            window.history.replaceState({}, "", nextUrl.toString());
            render(q);
        });
    }

    window.WIKI_RENDER = {
        initCategoryPage: initCategoryPage,
        initSearchPage: initSearchPage
    };
})();

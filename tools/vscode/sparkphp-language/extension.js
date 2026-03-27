"use strict";

const vscode = require("vscode");

const DIRECTIVES = [
  {
    label: "@layout",
    detail: "Switch layout",
    documentation: "Use `@layout('main')` to define the layout for the current Spark view."
  },
  {
    label: "@title",
    detail: "Set page title",
    documentation: "Use `@title('Page')` to define the page title exposed to the layout."
  },
  {
    label: "@partial",
    detail: "Render partial",
    documentation: "Use `@partial('user/card', $user)` to include a partial."
  },
  {
    label: "@if",
    detail: "Conditional block",
    documentation: "Spark conditional block. Pair with `@elseif`, `@else` and `@endif`."
  },
  {
    label: "@auth",
    detail: "Auth conditional",
    documentation: "Render a block for authenticated users, with optional `@else`."
  },
  {
    label: "@foreach",
    detail: "Loop block",
    documentation: "Spark loop block with integrated `@empty` fallback."
  },
  {
    label: "@form",
    detail: "Form helper",
    documentation: "Creates a form with CSRF and method spoofing handled automatically."
  },
  {
    label: "@input",
    detail: "Input helper",
    documentation: "Form field helper with label, old values and errors support."
  },
  {
    label: "@component",
    detail: "Component block",
    documentation: "Render a component partial with optional slots."
  },
  {
    label: "@slot",
    detail: "Component slot",
    documentation: "Define named slot content inside a component call."
  },
  {
    label: "@cache",
    detail: "Fragment cache",
    documentation: "Cache a rendered fragment using `@cache('key', ttl)`."
  },
  {
    label: "@lazy",
    detail: "Lazy block",
    documentation: "Render a placeholder that is replaced with fetched HTML later."
  },
  {
    label: "@json",
    detail: "JSON helper",
    documentation: "Render a value as JSON inside the template."
  },
  {
    label: "@meta",
    detail: "Meta helper",
    documentation: "Render meta tags from a Spark directive call."
  },
  {
    label: "@php",
    detail: "Inline PHP block",
    documentation: "Open a raw PHP block with `@php` and close it with `@endphp`."
  }
];

const PIPES = [
  { label: "money", detail: "Format as BRL currency" },
  { label: "date", detail: "Format date value" },
  { label: "relative", detail: "Relative time output" },
  { label: "limit", detail: "Truncate text to N chars" },
  { label: "safe_limit", detail: "Safe truncate HTML-ish text" },
  { label: "upper", detail: "Uppercase text" },
  { label: "lower", detail: "Lowercase text" },
  { label: "title", detail: "Title-case text" },
  { label: "initials", detail: "Extract initials" },
  { label: "number", detail: "Format number" },
  { label: "bytes", detail: "Human-readable byte size" },
  { label: "count", detail: "Count items" },
  { label: "slug", detail: "Slugify text" },
  { label: "nl2br", detail: "Convert line breaks to <br>" }
];

function activate(context) {
  const selector = [{ language: "spark", scheme: "file" }, { language: "spark", scheme: "untitled" }];

  context.subscriptions.push(
    vscode.languages.registerCompletionItemProvider(selector, createDirectiveProvider(), "@"),
    vscode.languages.registerCompletionItemProvider(selector, createPipeProvider(), "|"),
    vscode.languages.registerHoverProvider(selector, createHoverProvider()),
    vscode.commands.registerCommand("sparkphpLanguage.showReference", async () => {
      const items = DIRECTIVES.map((directive) => `${directive.label} - ${directive.detail}`);
      await vscode.window.showQuickPick(items, {
        title: "SparkPHP Reference",
        placeHolder: "Select a directive"
      });
    })
  );
}

function createDirectiveProvider() {
  return {
    provideCompletionItems(document, position) {
      const linePrefix = document.lineAt(position).text.slice(0, position.character);
      if (!linePrefix.includes("@")) {
        return [];
      }

      return DIRECTIVES.map((directive) => {
        const item = new vscode.CompletionItem(directive.label, vscode.CompletionItemKind.Keyword);
        item.detail = directive.detail;
        item.documentation = new vscode.MarkdownString(directive.documentation);
        item.insertText = directive.label;
        return item;
      });
    }
  };
}

function createPipeProvider() {
  return {
    provideCompletionItems(document, position) {
      const linePrefix = document.lineAt(position).text.slice(0, position.character);
      if (!linePrefix.includes("|")) {
        return [];
      }

      return PIPES.map((pipe) => {
        const item = new vscode.CompletionItem(pipe.label, vscode.CompletionItemKind.Function);
        item.detail = pipe.detail;
        item.insertText = pipe.label;
        return item;
      });
    }
  };
}

function createHoverProvider() {
  return {
    provideHover(document, position) {
      const range = document.getWordRangeAtPosition(position, /@?[A-Za-z_][A-Za-z0-9_]*/);
      if (!range) {
        return null;
      }

      const word = document.getText(range);
      const directive = DIRECTIVES.find((item) => item.label === word);
      if (directive) {
        return new vscode.Hover(
          new vscode.MarkdownString(`**${directive.label}**\n\n${directive.documentation}`)
        );
      }

      const pipe = PIPES.find((item) => item.label === word);
      if (pipe) {
        return new vscode.Hover(
          new vscode.MarkdownString(`**${pipe.label}**\n\n${pipe.detail}`)
        );
      }

      return null;
    }
  };
}

function deactivate() {}

module.exports = {
  activate,
  deactivate
};

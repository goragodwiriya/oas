class ExpressionEvaluator {
  static operators = {
    '+': (a, b) => Number(a) + Number(b),
    '-': (a, b) => Number(a) - Number(b),
    '*': (a, b) => Number(a) * Number(b),
    '/': (a, b) => Number(a) / Number(b),
    '%': (a, b) => Number(a) % Number(b),
    '===': (a, b) => a === b,
    '!==': (a, b) => a !== b,
    '!=': (a, b) => a != b,
    '>': (a, b) => a > b,
    '>=': (a, b) => a >= b,
    '<': (a, b) => a < b,
    '<=': (a, b) => a <= b,
    '==': (a, b) => a == b,
    '&&': (a, b) => a && b,
    '||': (a, b) => a || b,
    '!': a => !a,

    '+str': (a, b) => String(a) + String(b)
  };

  // Blocked property names to prevent prototype chain traversal
  static dangerousKeys = new Set(['__proto__', 'constructor', 'prototype']);

  // Pre-compiled regex for simple property path detection (performance)
  static simplePathRegex = /^[\w.]+$/;

  // Detect bracket property access: word[...] pattern (not starting with [)
  static bracketAccessRegex = /^[\w.]+\[/;

  static evaluate(expression, state, context) {
    try {
      if (!expression?.trim()) return undefined;

      // Pre-process mustache interpolations: resolve {{expr}} innermost-first
      // e.g. "topic[{{lng.value}}]" → resolve {{lng.value}} = "th" → "topic['th']"
      if (expression.includes('{{')) {
        expression = this.resolveInterpolation(expression, state, context);
      }

      const trimmed = expression.trim();

      const normalizedExpression = this.resolveParenthesizedExpressions(trimmed, state, context);
      if (normalizedExpression !== trimmed) {
        return this.evaluate(normalizedExpression, state, context);
      }

      if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
        return this.parseObjectLiteral(trimmed, state, context);
      }

      if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
        return this.parseArrayLiteral(trimmed, state, context);
      }

      if (expression === '!true' || expression === '!false') {
        const value = expression === '!true' ? true : false;
        return !value;
      }

      // Handle string literals (single or double quoted)
      if (this.isSimpleStringLiteral(expression)) {
        return expression.slice(1, -1); // Return string without quotes
      }

      if (/^-?\d+(\.\d+)?$/.test(trimmed)) {
        return Number(trimmed);
      }

      if (trimmed === 'true') {
        return true;
      }

      if (trimmed === 'false') {
        return false;
      }

      if (trimmed === 'null') {
        return null;
      }

      if (trimmed === 'undefined') {
        return undefined;
      }

      // Handle ternary expression: condition ? trueValue : falseValue
      // Use bracket/quote-aware splitting to handle nested ternary and strings with colons
      const ternaryParts = this.splitTernary(expression);
      if (ternaryParts) {
        const {condition, trueExpr, falseExpr} = ternaryParts;
        const conditionResult = this.evaluate(condition, state, context);
        const isTruthy = conditionResult && conditionResult !== '0' && conditionResult !== 0;
        return isTruthy
          ? this.evaluate(trueExpr, state, context)
          : this.evaluate(falseExpr, state, context);
      }

      if (this.simplePathRegex.test(expression)) {
        const value = this.getPropertyPath(expression, state, context);
        return value;
      }

      // Handle bracket property access: topic[lng.value], items[0].name, a.b[c.d].e
      if (this.bracketAccessRegex.test(trimmed)) {
        const value = this.resolveBracketAccess(trimmed, state, context);
        if (value !== undefined) return value;
      }

      const tokens = this.tokenize(expression);

      let value;
      const postfix = this.toPostfix(tokens);
      const canUseStateFunctionShortcut = postfix.length === 2
        && typeof postfix[0] === 'string'
        && typeof postfix[1] === 'string'
        && !(postfix[0] in this.operators)
        && !(postfix[1] in this.operators)
        && state
        && typeof state[postfix[0]] === 'function';

      if (canUseStateFunctionShortcut) {
        const property = this.getPropertyPath(postfix[1], state, context);
        value = property === undefined ? undefined : state[postfix[0]].call(context, property);
      } else {
        value = this.evaluatePostfix(postfix, state, context);
      }
      return value;
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'ExpressionEvaluator.evaluate',
        data: {
          expression,
          state,
          context
        }
      });
      return undefined;
    }
  }

  static getPropertyPath(path, state, context) {
    if (typeof path !== 'string') {
      return path;
    }
    const parts = path.split('.');

    // Block dangerous property names to prevent prototype chain traversal
    if (parts.some(key => this.dangerousKeys.has(key))) {
      return undefined;
    }

    const hasOwn = Object.prototype.hasOwnProperty;

    // First try to resolve from the provided context/state (existing behavior)
    const resolved = parts.reduce((obj, key) => {
      if (obj === undefined || obj === null) return undefined;

      if (obj.computed && typeof obj.computed[key] === 'function') {
        return obj.computed[key].call(obj);
      }

      let value = obj[key];
      if (value !== undefined) {
        if (typeof value === 'function') {
          value = value.call(context);
        }
        return value;
      }

      if (state && hasOwn.call(state, key)) {
        const value = typeof state[key] === 'function' ? state[key].call(context) : state[key];
        return value;
      }

      if (obj.state && hasOwn.call(obj.state, key)) {
        return obj.state[key];
      }

      return undefined;
    }, context || state);

    if (resolved !== undefined) return resolved;

    return undefined;
  }

  /**
   * Resolve property access with bracket notation.
   * Supports mixed dot and bracket notation:
   *   topic[lng.value]      → state.topic[ evaluate("lng.value") ]
   *   a.b[c.d].e            → state.a.b[ evaluate("c.d") ].e
   *   items[0]              → state.items[0]
   *   topic['th']           → state.topic["th"]
   *
   * @param {string} expression - e.g. "topic[lng.value]"
   * @param {Object} state
   * @param {Object} context
   * @returns {*} resolved value or undefined
   */
  static resolveBracketAccess(expression, state, context) {
    // Parse into segments: {type:'prop', value:'topic'}, {type:'bracket', value:'lng.value'}, ...
    const segments = [];
    let i = 0;
    let current = '';
    let bracketDepth = 0;

    while (i < expression.length) {
      const char = expression[i];

      if (char === '.' && bracketDepth === 0) {
        if (current) segments.push({type: 'prop', value: current});
        current = '';
        i++;
      } else if (char === '[') {
        if (current) segments.push({type: 'prop', value: current});
        current = '';
        i++; // skip [
        // Find matching ]
        let depth = 1;
        bracketDepth = 1;
        let bracketExpr = '';
        while (i < expression.length && depth > 0) {
          if (expression[i] === '[') depth++;
          if (expression[i] === ']') depth--;
          if (depth > 0) bracketExpr += expression[i];
          i++;
        }
        bracketDepth = 0;
        segments.push({type: 'bracket', value: bracketExpr.trim()});
      } else {
        current += char;
        i++;
      }
    }
    if (current) segments.push({type: 'prop', value: current});

    if (segments.length === 0) return undefined;

    // Resolve first segment from state/context (same as getPropertyPath for root lookup)
    let result;
    const first = segments[0];
    if (first.type === 'prop') {
      result = this.getPropertyPath(first.value, state, context);
    } else {
      // Bracket at root level — evaluate expression to get key
      const key = this.resolveBracketKey(first.value, state, context);
      result = (context || state)?.[key];
    }

    // Walk remaining segments
    for (let s = 1; s < segments.length; s++) {
      if (result === undefined || result === null) return undefined;
      const seg = segments[s];
      if (seg.type === 'prop') {
        result = result[seg.value];
      } else {
        // Evaluate bracket expression to get the dynamic key
        const key = this.resolveBracketKey(seg.value, state, context);
        if (key === undefined || key === null) return undefined;
        result = result[key];
      }
    }

    return result;
  }

  static resolveBracketKey(keyExpression, state, context) {
    const trimmed = String(keyExpression).trim();
    const evaluated = this.evaluate(trimmed, state, context);

    if (evaluated !== undefined && evaluated !== null) {
      return evaluated;
    }

    if (/^-?\d+$/.test(trimmed)) {
      return Number(trimmed);
    }

    if (/^[A-Za-z_$][\w$]*$/.test(trimmed)) {
      return trimmed;
    }

    return evaluated;
  }

  static toExpressionLiteral(value) {
    if (value === undefined) return 'undefined';
    if (value === null) return 'null';

    if (typeof value === 'string') {
      return `'${String(value)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")}'`;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
      return String(value);
    }

    if (Array.isArray(value) || typeof value === 'object') {
      return JSON.stringify(value);
    }

    return String(value);
  }

  static resolveParenthesizedExpressions(expression, state, context) {
    if (typeof expression !== 'string' || !expression.includes('(')) {
      return expression;
    }

    let result = expression;
    let safety = 0;

    while (result.includes('(') && safety < 100) {
      let inSingle = false;
      let inDouble = false;
      let start = -1;
      let end = -1;
      const stack = [];

      for (let index = 0; index < result.length; index++) {
        const char = result[index];
        const prev = index > 0 ? result[index - 1] : '';

        if (char === "'" && !inDouble && prev !== '\\') {
          inSingle = !inSingle;
          continue;
        }

        if (char === '"' && !inSingle && prev !== '\\') {
          inDouble = !inDouble;
          continue;
        }

        if (inSingle || inDouble) {
          continue;
        }

        if (char === '(') {
          stack.push(index);
          continue;
        }

        if (char === ')' && stack.length > 0) {
          const candidateStart = stack.pop();
          const leading = result.slice(0, candidateStart).trimEnd();
          const prevNonWhitespace = leading.charAt(leading.length - 1);

          if (/[\w\]\)]/.test(prevNonWhitespace)) {
            continue;
          }

          start = candidateStart;
          end = index;
          break;
        }
      }

      if (start === -1 || end === -1) {
        break;
      }

      const innerExpression = result.slice(start + 1, end).trim();
      const evaluated = innerExpression === ''
        ? ''
        : this.evaluate(innerExpression, state, context);
      const literal = innerExpression === '' ? '' : this.toExpressionLiteral(evaluated);

      result = `${result.slice(0, start)}${literal}${result.slice(end + 1)}`;
      safety++;
    }

    return result;
  }

  /**
   * Pre-process mustache interpolations within an expression string.
   * Resolves {{expr}} patterns innermost-first, replacing each with
   * its evaluated literal value so the outer expression can proceed.
   *
   * Examples:
   *   "topic[{{lng.value}}]"  →  "topic['th']"     (string result wrapped in quotes)
   *   "items[{{index}}]"      →  "items[2]"         (numeric result kept bare)
   *
   * @param {string} expression - Expression containing {{...}} patterns
   * @param {Object} state
   * @param {Object} context
   * @returns {string} Expression with all {{}} resolved to literals
   */
  static resolveInterpolation(expression, state, context) {
    return expression.replace(/\{\{(.+?)\}\}/g, (_match, inner) => {
      const value = this.evaluate(inner.trim(), state, context);
      if (value === undefined || value === null) return '';
      if (typeof value === 'number' || typeof value === 'boolean') return String(value);
      // Wrap string values in single quotes, escaping any internal quotes
      return `'${String(value).replace(/'/g, "\\'")}'`;
    });
  }

  static isSimpleStringLiteral(expression) {
    if (!expression) return false;
    const trimmed = expression.trim();
    if (trimmed.length < 2) return false;

    const quote = trimmed[0];
    if ((quote !== '"' && quote !== "'") || trimmed[trimmed.length - 1] !== quote) {
      return false;
    }

    // If there is another unescaped quote inside, it's not a simple literal
    for (let i = 1; i < trimmed.length - 1; i++) {
      if (trimmed[i] === quote && trimmed[i - 1] !== '\\') {
        return false;
      }
    }

    return true;
  }

  static parseArrayLiteral(expression, state, context) {
    const content = expression.slice(1, -1).trim();
    if (!content) return [];

    const items = this.splitTopLevel(content, ',');
    return items.map(item => this.evaluate(item.trim(), state, context));
  }

  static parseObjectLiteral(expression, state, context) {
    const content = expression.slice(1, -1).trim();
    if (!content) return {};

    const pairs = this.splitTopLevel(content, ',');
    const result = {};

    pairs.forEach(pair => {
      const [keyRaw, valueRaw] = this.splitKeyValue(pair);
      if (!keyRaw || valueRaw === null) return;

      const key = keyRaw.trim().replace(/^['"]|['"]$/g, '');
      if (!key) return;

      const valueExpr = valueRaw.trim();
      result[key] = this.evaluate(valueExpr, state, context);
    });

    return result;
  }

  static splitKeyValue(pair) {
    let inSingle = false;
    let inDouble = false;
    let parenDepth = 0;
    let braceDepth = 0;
    let bracketDepth = 0;

    for (let i = 0; i < pair.length; i++) {
      const char = pair[i];

      if (char === "'" && !inDouble) {
        inSingle = !inSingle;
        continue;
      }

      if (char === '"' && !inSingle) {
        inDouble = !inDouble;
        continue;
      }

      if (!inSingle && !inDouble) {
        if (char === '(') parenDepth++;
        if (char === ')' && parenDepth > 0) parenDepth--;
        if (char === '{') braceDepth++;
        if (char === '}' && braceDepth > 0) braceDepth--;
        if (char === '[') bracketDepth++;
        if (char === ']' && bracketDepth > 0) bracketDepth--;
      }

      if (char === ':' && !inSingle && !inDouble && parenDepth === 0 && braceDepth === 0 && bracketDepth === 0) {
        return [pair.slice(0, i), pair.slice(i + 1)];
      }
    }

    return [null, null];
  }

  static splitTopLevel(input, delimiter) {
    const parts = [];
    let current = '';
    let inSingle = false;
    let inDouble = false;
    let parenDepth = 0;
    let braceDepth = 0;
    let bracketDepth = 0;

    for (let i = 0; i < input.length; i++) {
      const char = input[i];

      if (char === "'" && !inDouble) {
        inSingle = !inSingle;
      } else if (char === '"' && !inSingle) {
        inDouble = !inDouble;
      } else if (!inSingle && !inDouble) {
        if (char === '(') parenDepth++;
        if (char === ')' && parenDepth > 0) parenDepth--;
        if (char === '{') braceDepth++;
        if (char === '}' && braceDepth > 0) braceDepth--;
        if (char === '[') bracketDepth++;
        if (char === ']' && bracketDepth > 0) bracketDepth--;
      }

      if (char === delimiter && !inSingle && !inDouble && parenDepth === 0 && braceDepth === 0 && bracketDepth === 0) {
        if (current.trim()) parts.push(current.trim());
        current = '';
        continue;
      }

      current += char;
    }

    if (current.trim()) parts.push(current.trim());
    return parts;
  }

  /**
   * Split a ternary expression into condition, trueExpr, falseExpr
   * Handles nested ternary and strings containing ? or : safely
   * @param {string} expression
   * @returns {{ condition: string, trueExpr: string, falseExpr: string } | null}
   */
  static splitTernary(expression) {
    let inSingle = false;
    let inDouble = false;
    let depth = 0; // track nested ternary depth
    let questionPos = -1;
    let colonPos = -1;

    for (let i = 0; i < expression.length; i++) {
      const char = expression[i];
      const prev = i > 0 ? expression[i - 1] : '';

      if (char === "'" && !inDouble && prev !== '\\') {inSingle = !inSingle; continue;}
      if (char === '"' && !inSingle && prev !== '\\') {inDouble = !inDouble; continue;}

      if (inSingle || inDouble) continue;

      if (char === '(') {depth++; continue;}
      if (char === ')') {depth--; continue;}

      if (depth > 0) continue;

      if (char === '?' && questionPos === -1) {
        questionPos = i;
        continue;
      }

      // After finding ?, track nested ternary depth for : matching
      if (questionPos !== -1) {
        if (char === '?') {depth++; continue;}
        if (char === ':') {
          if (depth > 0) {depth--; continue;}
          colonPos = i;
          break;
        }
      }
    }

    if (questionPos === -1 || colonPos === -1) return null;

    const condition = expression.substring(0, questionPos).trim();
    const trueExpr = expression.substring(questionPos + 1, colonPos).trim();
    const falseExpr = expression.substring(colonPos + 1).trim();

    if (!condition || !trueExpr || !falseExpr) return null;

    return {condition, trueExpr, falseExpr};
  }

  static tokenize(expression) {
    const tokens = [];
    let current = '';

    const pushToken = () => {
      if (current) {
        tokens.push(current);
        current = '';
      }
    };

    for (let i = 0; i < expression.length; i++) {
      const char = expression[i];

      if (char === '"' || char === "'") {
        const quote = char;
        pushToken();
        current = char;
        i++;
        while (i < expression.length && expression[i] !== quote) {
          current += expression[i];
          i++;
        }
        current += quote;
        pushToken();
        continue;
      }

      if (/[+\-*/%=!&|<>]/.test(char)) {
        pushToken();
        let operator = char;

        let next = expression[i + 1];
        while (next && /[=&|><]/.test(next)) {
          operator += next;
          i++;
          next = expression[i + 1];
        }

        tokens.push(operator);
        continue;
      }

      if (char === '(' || char === ')') {
        pushToken();
        tokens.push(char);
        continue;
      }

      if (/\s/.test(char)) {
        pushToken();
        continue;
      }

      current += char;
    }

    pushToken();
    return tokens;
  }

  static toPostfix(tokens) {
    const output = [];
    const operators = [];
    const precedence = {
      '!': 4,
      '*': 3, '/': 3, '%': 3,
      '+': 2, '-': 2,
      '>=': 1, '<=': 1, '>': 1, '<': 1,
      '===': 1, '!==': 1, '==': 1, '!=': 1,
      '&&': 0, '||': 0
    };

    tokens.forEach(token => {
      if (token in this.operators) {
        while (operators.length > 0 &&
          operators[operators.length - 1] !== '(' &&
          precedence[operators[operators.length - 1]] >= precedence[token]) {
          output.push(operators.pop());
        }
        operators.push(token);
      } else if (token === '(') {
        operators.push(token);
      } else if (token === ')') {
        while (operators.length > 0 && operators[operators.length - 1] !== '(') {
          output.push(operators.pop());
        }
        operators.pop();
      } else {
        output.push(token);
      }
    });

    while (operators.length > 0) {
      output.push(operators.pop());
    }

    return output;
  }

  static evaluatePostfix(postfix, state, context) {
    const stack = [];

    postfix.forEach(token => {
      if (token in this.operators) {
        const operator = this.operators[token];
        const arity = token === '!' ? 1 : 2;
        const args = stack.splice(-arity);

        const values = args.map(arg => {
          if (arg && typeof arg === 'object' && arg.__exprValue) {
            return arg.value;
          }
          if (/^["'].*["']$/.test(arg)) {
            return arg.slice(1, -1);
          }
          if (/^-?\d+(\.\d+)?$/.test(arg)) {
            return Number(arg);
          }
          if (arg === 'true') return true;
          if (arg === 'false') return false;
          if (arg === 'null') return null;
          if (arg === 'undefined') return undefined;
          return this.getPropertyPath(arg, state, context);
        });

        if (token === '||') {
          const leftValue = values[0];
          const rightValue = values[1];

          stack.push({
            __exprValue: true,
            value: leftValue !== undefined ? (leftValue || rightValue) : rightValue
          });
          return;
        }

        if (token === '&&') {
          const leftValue = values[0];
          const rightValue = values[1];

          stack.push({
            __exprValue: true,
            value: leftValue ? rightValue : leftValue
          });
          return;
        }

        // If any required operand is undefined, push undefined and move on
        // (do NOT return — that would corrupt the stack since args were already spliced)
        const hasUndefined = arity === 1
          ? values[0] === undefined
          : values[0] === undefined || values[1] === undefined;

        if (hasUndefined) {
          stack.push({__exprValue: true, value: undefined});
          return;
        }

        if (token === '+' && (typeof values[0] === 'string' || typeof values[1] === 'string')) {
          stack.push({__exprValue: true, value: String(values[0]) + String(values[1])});
        } else {
          stack.push({__exprValue: true, value: operator(...values)});
        }
      } else {
        stack.push(token);
      }
    });

    const result = stack[0];
    return result && typeof result === 'object' && result.__exprValue ? result.value : result;
  }
}

window.ExpressionEvaluator = ExpressionEvaluator;

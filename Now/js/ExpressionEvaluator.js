/**
 * ExpressionEvaluator
 *
 * Evaluates the small expression language used inside data attributes —
 * `data-if`, `data-attr`, `data-for` and friends. It is a hand-written
 * tokenizer plus shunting-yard parser rather than `eval`, so page markup can
 * never execute arbitrary JavaScript.
 *
 * Two safety rules run through the whole file:
 * - Property paths and function names are rejected when any segment is in
 *   `dangerousKeys`, which blocks prototype-chain traversal.
 * - Values that cannot be represented as text safely are replaced by a ref
 *   token (`REF_PREFIX` + counter) held in `_refs`, and the token is never
 *   allowed to reach the rendered output.
 */
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

  static _refs = new Map();
  static _refSeq = 0;
  static _depth = 0;
  static REF_PREFIX = '__expr_ref_';

  /**
   * Store a value behind a ref token.
   *
   * Used for values that cannot survive a round trip through text — functions,
   * objects, anything whose `String()` form would lose meaning. The token is a
   * placeholder that only this class can resolve.
   *
   * @param {*} value - Value to keep.
   * @returns {string} - The ref token standing in for it.
   */
  static makeRef(value) {
    const key = this.REF_PREFIX + (this._refSeq++);
    this._refs.set(key, value);
    return key;
  }

  /**
   * Whether a string is a live ref token.
   *
   * Checks both the prefix and that the token is still registered, so text that
   * merely looks like a token is not mistaken for one.
   *
   * @param {*} name - Candidate token.
   * @returns {boolean} - True when it resolves to a stored value.
   */
  static isRef(name) {
    return typeof name === 'string'
      && name.startsWith(this.REF_PREFIX)
      && this._refs.has(name);
  }

  /** Only round-trip values ​​are safe to use as text, the rest use ref. */
  static toSubstitution(value) {
    const t = typeof value;
    if (value === null || value === undefined || t === 'number' || t === 'boolean') {
      return this.toExpressionLiteral(value);
    }
    return this.makeRef(value);
  }

  /**
   * Evaluate an expression against a state object.
   *
   * Recursion depth is tracked so nested evaluation cannot run away. Before
   * returning, any ref token left in a string result is swapped back for its
   * real value — a token must never be shown to the user.
   *
   * @param {string} expression - Expression source.
   * @param {Object} state - Reactive state the expression reads from.
   * @param {Object} context - Extra scope, e.g. the current `data-for` item.
   * @returns {*} - The evaluated value.
   */
  static evaluate(expression, state, context) {
    this._depth++;
    try {
      let out = this._evaluateInner(expression, state, context);
      // safety net: The ref token must not be leaked as a message to the user.
      if (typeof out === 'string' && out.includes(this.REF_PREFIX)) {
        if (this.isRef(out)) return this._refs.get(out);
        out = out.replace(/__expr_ref_\d+/g, m => this.isRef(m) ? String(this._refs.get(m)) : m);
      }
      return out;
    } finally {
      if (--this._depth === 0) {
        this._refs.clear();
        this._refSeq = 0;
      }
    }
  }

  static _evaluateInner(expression, state, context) {
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

        // Nothing but a property path — it has genuinely resolved to undefined ·
        // falling through to the tokenizer would hand back the raw text instead,
        // which `data-if` reads as truthy and so shows what should stay hidden
        if (this.isPureBracketPath(trimmed)) {
          return undefined;
        }
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

  /**
   * Read a dotted path out of state or context.
   *
   * Refuses the whole path when any segment is in `dangerousKeys`, so an
   * expression cannot walk into `__proto__` or `constructor` and reach the
   * prototype chain.
   *
   * @param {string} path - Dotted path, e.g. `user.name`.
   * @param {Object} state - Reactive state.
   * @param {Object} context - Extra scope, checked before state.
   * @returns {*} - The value, or undefined when the path is missing or blocked.
   */
  static getPropertyPath(path, state, context) {
    if (typeof path !== 'string') {
      return path;
    }
    const parts = path.split('.');

    // Block dangerous property names to prevent prototype chain traversal
    if (parts.some(key => this.dangerousKeys.has(key))) {
      return undefined;
    }

    // [PATCH] ref token is the root of the path: __expr_ref_0.a.b
    if (this.isRef(parts[0])) {
      let value = this._refs.get(parts[0]);
      for (let i = 1; i < parts.length; i++) {
        if (value === undefined || value === null) return undefined;
        value = value[parts[i]];
        if (typeof value === 'function') return value;
      }
      return value;
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
  /**
   * True when the expression is a property path and nothing else —
   * `a['b'].c` yes, `a['b'] && c` no · brackets may hold anything, what matters
   * is that no operator sits outside them
   */
  static isPureBracketPath(expression) {
    let depth = 0;

    for (let i = 0; i < expression.length; i++) {
      const char = expression[i];

      if (char === '[') {
        depth++;
      } else if (char === ']') {
        if (--depth < 0) return false;
      } else if (depth === 0 && !/[\w.]/.test(char)) {
        return false;
      }
    }

    return depth === 0;
  }

  /**
   * Resolve `a[b]` access, where the key is itself an expression.
   *
   * The path is split into property and bracket segments so `topic[lng.value]`
   * evaluates `lng.value` first and uses the result as the key.
   *
   * @param {string} expression - Expression source containing brackets.
   * @param {Object} state - Reactive state.
   * @param {Object} context - Extra scope.
   * @returns {*} - The value at the resolved path.
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

  /**
   * Work out the key inside a bracket.
   *
   * The inner expression is evaluated first; when that yields nothing usable the
   * raw text is treated as a literal key instead.
   *
   * @param {string} keyExpression - Text between the brackets.
   * @param {Object} state - Reactive state.
   * @param {Object} context - Extra scope.
   * @returns {*} - The key to look up.
   */
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

  /**
   * Render a value back into expression source.
   *
   * Used when a resolved value must be substituted into the expression text
   * before the next parsing pass; strings come back quoted and escaped.
   *
   * @param {*} value - Value to render.
   * @returns {string} - Literal source for that value.
   */
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

  static functions = new Map();
  static MAX_CALL_ARGS = 8;

  /**
   * Make a function callable from expressions.
   *
   * Rejected when the name is not a plain dotted identifier, when any segment is
   * in `dangerousKeys`, or when it would collide with the ref-token prefix.
   *
   * @param {string} name - Name used in expressions, dots allowed.
   * @param {Function} fn - Implementation.
   * @returns {boolean} - True when the function was registered.
   */
  static registerFunction(name, fn) {
    if (typeof name !== 'string' || typeof fn !== 'function') return false;
    if (!/^[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*$/.test(name)) return false;
    if (name.split('.').some(k => this.dangerousKeys.has(k))) return false;
    if (name.startsWith(this.REF_PREFIX)) return false;
    this.functions.set(name, fn);
    return true;
  }

  /**
   * Register several functions at once.
   *
   * Entries that fail `registerFunction` are skipped rather than aborting the
   * batch, so one bad name cannot block the rest.
   *
   * @param {Object} map - Name to function.
   * @param {string} [prefix=''] - Prepended to every name.
   * @returns {number} - How many were actually registered.
   */
  static registerFunctions(map, prefix = '') {
    let n = 0;
    for (const [k, v] of Object.entries(map || {})) {
      if (typeof v === 'function' && this.registerFunction(prefix + k, v)) n++;
    }
    return n;
  }

  // Find a callee without walking the property chain
  /**
   * Resolve the function an expression is trying to call.
   *
   * Blocks dangerous key segments, and when the name is a ref token only
   * resolves it if the stored value really is a function.
   *
   * @param {string} name - Callee name or ref token.
   * @param {Object} state - Reactive state.
   * @param {Object} context - Extra scope.
   * @returns {Function|undefined} - The function, or undefined when not callable.
   */
  static resolveCallee(name, state, context) {
    if (!name) return undefined;
    if (name.split('.').some(k => this.dangerousKeys.has(k))) return undefined;
    if (this.isRef(name)) {
      const v = this._refs.get(name);
      return typeof v === 'function' ? v : undefined;
    }
    const hasOwn = Object.prototype.hasOwnProperty;
    if (!name.includes('.')) {
      if (context && context.filters && hasOwn.call(context.filters, name)
        && typeof context.filters[name] === 'function') return context.filters[name];
      if (state && hasOwn.call(state, name) && typeof state[name] === 'function') return state[name];
      if (context && hasOwn.call(context, name) && typeof context[name] === 'function') return context[name];
    }
    const fn = this.functions.get(name);
    return typeof fn === 'function' ? fn : undefined;
  }

  /**
   * Split a call's argument list.
   *
   * Quote state respects backslash escapes, so an escaped quote inside a string
   * argument does not end the string early.
   *
   * @param {string} argsStr - Text between the call parentheses.
   * @returns {Array<string>} - One entry per argument.
   */
  static splitCallArgs(argsStr) {
    const out = [];
    let cur = '', s = false, d = false, depth = 0;
    for (let i = 0; i < argsStr.length; i++) {
      const c = argsStr[i], p = i > 0 ? argsStr[i - 1] : '';
      if (c === "'" && !d && p !== '\\') {s = !s; cur += c; continue;}
      if (c === '"' && !s && p !== '\\') {d = !d; cur += c; continue;}
      if (!s && !d) {
        if (c === '(' || c === '[' || c === '{') depth++;
        else if (c === ')' || c === ']' || c === '}') depth--;
        else if (c === ',' && depth === 0) {out.push(cur.trim()); cur = ''; continue;}
      }
      cur += c;
    }
    if (cur.trim()) out.push(cur.trim());
    return out;
  }

  /**
   * Evaluate innermost parentheses first, replacing each with its result.
   *
   * Loops until no parentheses remain, with a safety counter so a malformed
   * expression cannot spin forever.
   *
   * @param {string} expression - Expression source.
   * @param {Object} state - Reactive state.
   * @param {Object} context - Extra scope.
   * @returns {string} - Expression with parenthesised parts substituted.
   */
  static resolveParenthesizedExpressions(expression, state, context) {
    if (typeof expression !== 'string' || !expression.includes('(')) {
      return expression;
    }

    let result = expression;
    let safety = 0;

    while (result.includes('(') && safety < 200) {
      let inSingle = false, inDouble = false;
      let start = -1, end = -1, call = null;
      const stack = [];

      for (let index = 0; index < result.length; index++) {
        const char = result[index];
        const prev = index > 0 ? result[index - 1] : '';

        if (char === "'" && !inDouble && prev !== '\\') {inSingle = !inSingle; continue;}
        if (char === '"' && !inSingle && prev !== '\\') {inDouble = !inDouble; continue;}
        if (inSingle || inDouble) continue;

        if (char === '(') {stack.push(index); continue;}

        if (char === ')' && stack.length > 0) {
          const candidateStart = stack.pop();
          const leading = result.slice(0, candidateStart).trimEnd();
          const prevNonWhitespace = leading.charAt(leading.length - 1);

          if (/[\w\]\)]/.test(prevNonWhitespace)) {
            const m = /[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*$/.exec(leading);
            if (m) {
              const fn = this.resolveCallee(m[0], state, context);
              if (fn) {
                call = {
                  calleeStart: leading.length - m[0].length,
                  argsStart: candidateStart + 1, end: index, fn, name: m[0]
                };
                break;
              }
            }
            continue;                 // Can't find callee = Same behavior.
          }

          start = candidateStart;
          end = index;
          break;
        }
      }

      if (call) {
        const argsStr = result.slice(call.argsStart, call.end).trim();
        const argExprs = argsStr === '' ? [] : this.splitCallArgs(argsStr);
        if (argExprs.length > this.MAX_CALL_ARGS) break;
        let value;
        try {
          value = call.fn.apply(context ?? null, argExprs.map(a => this.evaluate(a, state, context)));
        } catch (e) {
          ErrorManager.handle(e, {
            context: 'ExpressionEvaluator.call',
            data: {name: call.name, expression}
          });
          value = undefined;
        }
        result = result.slice(0, call.calleeStart) + this.toSubstitution(value) + result.slice(call.end + 1);
        safety++;
        continue;
      }

      if (start === -1 || end === -1) break;

      const innerExpression = result.slice(start + 1, end).trim();
      const evaluated = innerExpression === '' ? '' : this.evaluate(innerExpression, state, context);
      const literal = innerExpression === '' ? '' : this.toSubstitution(evaluated);

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

  /**
   * Whether an expression is nothing more than a quoted string.
   *
   * Lets the caller skip tokenising and parsing when the expression is plain
   * text in quotes.
   *
   * @param {string} expression - Expression source.
   * @returns {boolean} - True when it is a single string literal.
   */
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

  /**
   * Evaluate an array literal.
   *
   * Items are split at top level only, so a nested array or a comma inside a
   * string stays in one piece, then each item is evaluated.
   *
   * @param {string} expression - Source including the surrounding brackets.
   * @param {Object} state - Reactive state.
   * @param {Object} context - Extra scope.
   * @returns {Array} - The evaluated items.
   */
  static parseArrayLiteral(expression, state, context) {
    const content = expression.slice(1, -1).trim();
    if (!content) return [];

    const items = this.splitTopLevel(content, ',');
    return items.map(item => this.evaluate(item.trim(), state, context));
  }

  /**
   * Evaluate an object literal.
   *
   * Pairs are split at top level, then each is split into key and value by
   * `splitKeyValue` so a colon inside a nested value is not mistaken for the
   * separator.
   *
   * @param {string} expression - Source including the surrounding braces.
   * @param {Object} state - Reactive state.
   * @param {Object} context - Extra scope.
   * @returns {Object} - The evaluated object.
   */
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

  /**
   * Split one object-literal pair into key and value at the top-level colon.
   *
   * Quotes and every kind of bracket are tracked, so a colon inside a nested
   * object, array or string is not treated as the separator.
   *
   * @param {string} pair - A single `key: value` pair.
   * @returns {Array<string>} - `[key, value]`.
   */
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

  /**
   * Split on a delimiter, ignoring ones nested inside quotes or brackets.
   *
   * @param {string} input - Text to split.
   * @param {string} delimiter - Delimiter to split on.
   * @returns {Array<string>} - The top-level parts.
   */
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

  /**
   * Split an expression into tokens.
   *
   * Quotes, brackets and parentheses are tracked so an operator inside a string
   * literal is not mistaken for a real operator.
   *
   * @param {string} expression - Expression source.
   * @returns {Array<string>} - Tokens in source order.
   */
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

      // Bracket access belongs to the token in front of it — `permissions['a.b']`
      // is ONE operand, not three · without this the quote branch above split it
      // into `permissions[` / `'a.b'` / `]`, and every compound expression using
      // it (`role === 'x' && permissions['a.b']`) silently evaluated something else
      if (char === '[' && current !== '') {
        let depth = 0;

        while (i < expression.length) {
          const inner = expression[i];

          if (inner === '"' || inner === "'") {
            const quote = inner;
            current += inner;
            i++;
            while (i < expression.length && expression[i] !== quote) {
              current += expression[i];
              i++;
            }
            current += quote;
            i++;
            continue;
          }

          if (inner === '[') depth++;
          if (inner === ']') depth--;
          current += inner;
          i++;

          if (depth === 0) break;
        }

        i--; // the loop's own i++ would otherwise skip the character after `]`
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

  /**
   * Convert infix tokens to postfix using shunting-yard.
   *
   * Precedence runs `!` above `* / %`, above `+ -`, above the comparisons, with
   * the logical operators lowest, matching JavaScript closely enough for the
   * expressions data attributes actually contain.
   *
   * @param {Array<string>} tokens - Tokens from `tokenize`.
   * @returns {Array<string>} - Tokens in postfix order.
   */
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

  /**
   * Evaluate a postfix token list.
   *
   * Operands are pushed on a stack and each operator pops its arity — one for
   * `!`, two for everything else.
   *
   * @param {Array<string>} postfix - Tokens from `toPostfix`.
   * @param {Object} state - Reactive state the expression reads from.
   * @param {Object} context - Extra scope.
   * @returns {*} - The value left on the stack.
   */
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
          if (typeof arg === 'string' && this.bracketAccessRegex.test(arg)) {
            return this.resolveBracketAccess(arg, state, context);
          }
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

// Expose Utils formatting helpers to template expressions.
// Only these namespaces are callable — anything else must be registered explicitly
// via ExpressionEvaluator.registerFunction(), which is the only way a template
// can reach a function. Utils.js is imported before this file (see core-entry.js).
if (window.Utils) {
  for (const ns of ['number', 'string', 'date']) {
    if (window.Utils[ns]) {
      ExpressionEvaluator.registerFunctions(window.Utils[ns], `Utils.${ns}.`);
    }
  }
}

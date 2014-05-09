<?hh
/**
 * Copyright (c) 2014, PocketRent Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of PocketRent Ltd nor the names of its contributors
 *       may be used to endorse or promote products derived from this software
 *       without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

const int T_XHP_LABEL = 382;
const int T_SHAPE = 402;
const int T_NEWTYPE = 403;
const int T_TYPE = 405;
const int T_GROUP = 423;

final class SymbolParser {
  private int $index = 0;
  private $symbolMap = shape(
    'class' => array(),
    'function' => array(),
    'constant' => array(),
    'type' => array(),
  );

  private Vector<string> $namespaceScope = Vector {};

  public function __construct(
    private string $filename,
    private array<mixed> $tokens,
  ) {}

  public function parseSymbols(): SymbolMap {
    while ($this->index < count($this->tokens)) {
      $this->skipTokens();
      $token = $this->getToken();

      switch ($token) {
        case T_ABSTRACT:
        case T_FINAL:
          $this->bump();
          $this->skipWhitespace();
          // FALLTHROUGH
        case T_CLASS:
        case T_INTERFACE:
        case T_TRAIT:
          $this->bump();
          $name = $this->parseFQName();
          $this->addClass($name);
          $this->skipBlock();
          break;
        case T_NAMESPACE:
          $this->parseNamespace();
          break;
        case T_FUNCTION:
          $this->bump();
          if ($this->getToken() == T_STRING) {
            $name = $this->parseFQName();
            $this->addFunction($name);
            $this->skipBlock();
          }
          break;
        case T_TYPE:
        case T_NEWTYPE:
          $this->bump();
          $name = $this->parseFQName();
          $this->addType($name);
          $this->expectChar('=');
          $this->skipType();
          break;
        case T_CONST:
          $this->bump();
          $this->skipType();
          $name = $this->parseFQName();
          $this->addConst($name);
          break;
        case T_STRING:
          if ($this->getTokenValue() == 'define') {
            $this->bump();
            $this->expectChar('(');
            $str = $this->getTokenValue();
            $name = substr($str, 1, -1);
            $this->addConst($name);
          }
          // FALLTHROUGH
        default:
          $this->bump();
      }
    }

    return $this->symbolMap;
  }

  private function parseFQName(): string {
    $name = $this->parseName();
    return $this->qualify($name);
  }

  private function parseNamespace() {
    $this->expect(T_NAMESPACE);
    $ns = '';
    while ($this->index < count($this->tokens)) {
      if ($this->getToken() == T_NS_SEPARATOR) {
        if (strlen($ns) > 0)
          $ns .= '\\';
        $this->bump();
      } else if ($this->getToken() == T_STRING) {
        $ns .= $this->parseName();
      } else {
        if ($this->getTokenValue() == ';' &&
            $this->namespaceScope->count() > 0) {
          $this->namespaceScope->pop();
        }
        break;
      }
    }
    if ($ns != '') {
      $this->namespaceScope->add($ns);
    }
  }

  private function parseName(): string {
    $start_token = $this->getToken();
    if ($start_token == T_XHP_LABEL) {
      $element = $this->getTokenValue();
      $this->bump();
      return 'xhp_'.str_replace(array(':', '-'), array('__', '_'), $element);
    } else if ($start_token == T_STRING) {
      $name = $this->getTokenValue();
      $this->bump();
      return $name;
    } else if ($start_token == T_GROUP) {
      $name = $this->getTokenValue();
      $this->bump();
      return $name;
    } else {
      throw new Exception(
        "Parse Error: Expected 'T_XHP_LABEL' or 'T_STRING', got '".
        token_name($start_token)."' in '".$this->filename."'"
      );
    }
  }

  private function addClass(string $name) {
    $this->symbolMap['class'][strtolower($name)] = $this->filename;
  }

  private function addFunction(string $name) {
    $this->symbolMap['function'][strtolower($name)] = $this->filename;
  }

  private function addType(string $name) {
    $this->symbolMap['type'][strtolower($name)] = $this->filename;
  }

  private function addConst(string $name) {
    $this->symbolMap['constant'][$name] = $this->filename;
  }

  private function qualify(string $name): string {
    if ($this->namespaceScope->count() == 0)
      return $name;
    else
      return implode('\\', $this->namespaceScope->toArray()).'\\'.$name;
  }

  private function getToken(): int {
    $this->skipWhitespace();
    if ($this->index >= count($this->tokens))
      return -1;
    $token = $this->tokens[$this->index];
    if (is_array($token)) {
      return $token[0];
    } else {
      return -1;
    }
  }

  private function getTokenValue(): string {
    $this->skipWhitespace();
    if ($this->index >= count($this->tokens))
      return "";
    $token = $this->tokens[$this->index];
    if (is_array($token)) {
      return $token[1];
    } else {
      return (string)$token;
    }
  }

  private function expect(int $token) {
    if ($this->getToken() == $token) {
      $this->bump();
    } else {
      $expect = token_name($token);
      $actual = token_name($this->getToken());
      throw new Exception("Parse Error: Expected '$expect', got '$actual'");
    }
  }

  private function expectChar(string $token) {
    if ($this->getTokenValue() == $token) {
      $this->bump();
    } else {
      $expect = $token;
      $actual = $this->getTokenValue();
      throw new Exception("Parse Error: Expected '$expect', got '$actual'");
    }
  }

  private function bump() {
    $this->index++;
  }

  private function skipWhitespace() {
    while ($this->index < count($this->tokens)) {
      $token = $this->tokens[$this->index];
      if (is_array($token)) {
        if ($token[0] != T_WHITESPACE)
          return;
      } else {
        return;
      }
      $this->index++;
    }
  }

  private function skipBlock(string $from = '{', string $to = '}') {
    while ($this->index < count($this->tokens) &&
           $this->getTokenValue() != $from){
      $this->bump();
    }
    $scopes = 1;
    $this->bump();
    while ($scopes > 0) {
      if ($this->getTokenValue() == $from) {
        $scopes++;
      } else if ($this->getTokenValue() == $to) {
        $scopes--;
      }
      $this->bump();
    }
  }

  private function skipType() {
    switch ($this->getToken()) {
    case T_XHP_LABEL:
    case T_CALLABLE:
      $this->bump();
      break;
    case T_SHAPE:
      $this->skipBlock('(', ')');
      break;
    case T_ARRAY:
      $this->bump();
      if ($this->getTokenValue() == '<')
        $this->skipBlock('<', '>');
      break;
    case T_STRING:
      $this->bump();
      if ($this->getToken() == T_NS_SEPARATOR) {
        $this->skipType();
      }
      break;
    case T_NS_SEPARATOR:
      $this->bump();
      $this->skipType();
      break;
    default:
      switch ($this->getTokenValue()) {
      case '?':
      case '@':
        $this->bump();
        $this->skipType();
        break;
      case '(':
        $this->skipBlock('(', ')');
        break;
      }
    }
  }

  private function skipTokens(): void {
    while ($this->index < count($this->tokens)) {
      $token = $this->tokens[$this->index];
      if (is_array($token)) {
        switch ($token[0]) {
        case T_ABSTRACT:
        case T_CLASS:
        case T_CONST:
        case T_FINAL:
        case T_FUNCTION:
        case T_INTERFACE:
        case T_NAMESPACE:
        case T_NEWTYPE:
        case T_TRAIT:
        case T_TYPE:
        case T_STRING:
          return;
        }
      } else if ($token == '}') {
        if ($this->namespaceScope->count() > 0)
          $this->namespaceScope->pop();
      }
      $this->index++;
    }
  }
}

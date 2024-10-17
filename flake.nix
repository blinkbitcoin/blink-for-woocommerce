{
  description = "Blink Woocommerce plugin development environment";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = { self, nixpkgs, flake-utils }:
    flake-utils.lib.eachDefaultSystem (system:
      let
        pkgs = import nixpkgs {
          inherit system;
          config.allowUnfree = true;
        };
      in
      {
        devShell = pkgs.mkShell {
          buildInputs = with pkgs; [
            php83
            php83Packages.composer
            nodejs_20
          ];

          shellHook = ''
            echo "Blink Woocommerce plugin development environment"
            echo "PHP version: $(php --version | head -n 1)"
            echo "Composer version: $(composer --version | cut -d' ' -f3)"
            echo "Node.js version: $(node --version)"
          '';
        };
      }
    );
}

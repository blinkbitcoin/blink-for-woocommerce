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

        # Xdebug is required for the coverage gate: it is the only driver that
        # can produce branch coverage. pcov is a line-hit sampler and cannot,
        # so it is not a substitute here.
        php = pkgs.php83.buildEnv {
          extensions = { all, enabled }: enabled ++ [ all.xdebug ];
          extraConfig = ''
            xdebug.mode = off
            memory_limit = 1G
          '';
        };
      in
      {
        devShells.default = pkgs.mkShell {
          buildInputs = [
            php
            php.packages.composer
            pkgs.nodejs_24
            # bin/install-wp-tests.sh fetches the WordPress test library over
            # subversion, and needs a mysql client to create the test database.
            pkgs.subversion
            pkgs.mariadb.client
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

<?php
class blipfoto_importer {

    public static $version = 1.3;

    static function version() {
        return blipfoto_importer::$version;
    }

    static function option( $opt ) {
        $opts = blipfoto_importer::options();

        if ( isset( $opts[$opt] ) )
            return $opts[$opt];

        return false;
    }

    static function options_saved() {
        return ( blipfoto_importer::option( 'username' ) and blipfoto_importer::option( 'client-id' ) and blipfoto_importer::option( 'client-secret' ) and blipfoto_importer::option( 'access-token' ) );
    }

    static function options() {
        global $blipfoto_importer_settings;
        return $blipfoto_importer_settings->get();
    }

    static function new_client() {
        $token = blipfoto_importer::option('access-token');
        if(! $token) {
            $token = blipfoto_importer::option('client-id');
        }
        return new Blipfoto\Api\Client( blipfoto_importer::option( 'client-id' ), blipfoto_importer::option( 'client-secret' ), $token);
    }

}
